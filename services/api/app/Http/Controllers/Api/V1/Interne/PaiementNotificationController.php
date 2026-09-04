<?php

namespace App\Http\Controllers\Api\V1\Interne;

use App\Exceptions\PrincipalSigneInvalideException;
use App\Http\Controllers\Controller;
use App\Services\ClientPaiementGeniusPay;
use App\Services\CommissionService;
use App\Services\ResolveurEtablissementRef;
use App\Services\VerificateurPrincipalSigne;
use App\Support\PaiementStatut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Notification entrante paiement-service (Java) → Laravel — lot 6 (v2), volet 1 + B4 (2026-09-04).
 *
 * ═══ CE CONTRÔLEUR TRANSPORTE, PUIS DÉCLENCHE UNE COMMISSION SUR LE SEUL CANAL GENIUSPAY ═══
 * La v1/v2 initiale de ce lot n'appelait JAMAIS `CommissionService` : le payload ne portait ni
 * `etablissementRef` ni `factureId`, faute d'émetteur (Laravel n'initiait aucun paiement, P5.6a).
 * B4 fait de Laravel un émetteur (canal GeniusPay, {@see ClientPaiementGeniusPay}),
 * et l'événement Java ({@code TransitionTerminaleEvenement}) les porte désormais — voir ADR-056.
 *
 * ═══ POURQUOI « geniuspay » SEUL, ET PAS TOUT SUCCÈS PORTANT UN ÉTABLISSEMENT ═══
 * Le G0 de B4 avait supposé qu'`etablissementRef` suffirait à isoler les paiements GeniusPay. En
 * IMPLÉMENTANT (pas au G1), la relecture de `ServiceCarte`/`ServicePaiement` (Java) a montré que
 * la CARTE et le MOBILE MONEY portent EUX AUSSI un `etablissementRef` — filtrer sur sa seule
 * présence aurait calculé une commission MaSanté sur TOUS les paiements de la plateforme, une
 * décision de politique commerciale jamais prise. Le `canal` (ajouté à l'événement au même moment)
 * est le seul discriminant fiable : seul le canal `geniuspay` (montage A) porte cette commission.
 *
 * ═══ CE QUE « SUCCESS » SEUL SIGNIFIE ═══
 * Un échec, une annulation ou un remboursement n'ont rien à facturer : seul {@see
 * PaiementStatut::SUCCESS} déclenche un calcul. Aucune commission n'est jamais annulée
 * automatiquement ici sur un `REFUNDED` — un remboursement de commission est une décision
 * d'exploitation distincte, hors périmètre de ce lot.
 *
 * ═══ IDEMPOTENCE ═══
 * `paiementId` (ajouté à l'événement Java en cours d'exécution de B4) identifie SANS AMBIGUÏTÉ la
 * transition qui a déclenché cette notification : un `Paiement` n'atteint un état terminal qu'UNE
 * SEULE fois (garde de répétition de `setStatut`, côté Java), donc `geniuspay-paiement:{id}` est un
 * candidat sûr pour `reference_interne_paiement`, préfixé pour dire d'où il vient.
 *
 * Authentifié par principal signé (canal interne, `VerificateurPrincipalSigne`), JAMAIS par Sanctum :
 * cet appelant n'a pas de session utilisateur.
 */
class PaiementNotificationController extends Controller
{
    private const CANAL_GENIUSPAY = 'geniuspay';

    public function __construct(
        private readonly VerificateurPrincipalSigne $verificateur,
        private readonly ResolveurEtablissementRef $resolveur,
        private readonly CommissionService $commissions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->verificateur->verifier($request, (string) config('masante.paiement_service.principal_secret'));
        } catch (PrincipalSigneInvalideException $e) {
            Log::warning('Notification paiement-service refusée : principal signé invalide.', [
                'motif' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            abort(401);
        }

        $paiementId = $request->input('paiementId');
        $statut = $request->input('statut');
        $canal = $request->input('canal');
        $etablissementRef = $request->input('etablissementRef');
        $montant = $request->input('montant');
        $fraisPasserelle = $request->input('fraisPasserelle');
        $fraisPrestataire = $request->input('fraisPrestataire');
        $dateTransaction = $request->input('dateTransaction');

        Log::info('Notification paiement-service reçue et vérifiée.', [
            'paiementId' => $paiementId,
            'correlationId' => $request->input('correlationId'),
            'montant' => $montant,
            'statut' => $statut,
            'canal' => $canal,
            'dateTransaction' => $dateTransaction,
            'recu_le' => now()->toIso8601String(),
        ]);

        $this->calculerCommissionSiApplicable(
            paiementId: is_string($paiementId) ? $paiementId : null,
            statut: is_string($statut) ? $statut : null,
            canal: is_string($canal) ? $canal : null,
            etablissementRef: is_string($etablissementRef) ? $etablissementRef : null,
            montant: is_int($montant) ? $montant : null,
            fraisPasserelle: is_int($fraisPasserelle) ? $fraisPasserelle : null,
            fraisPrestataire: is_int($fraisPrestataire) ? $fraisPrestataire : 0,
            dateTransaction: is_string($dateTransaction) ? $dateTransaction : null,
        );

        return response()->json([], 200);
    }

    /**
     * B4 (ADR-056, S2/S10) — calcule une commission SEULEMENT sur un succès GeniusPay portant un
     * établissement résoluble. Refuse en le journalisant dans les autres cas incomplets — jamais
     * deviné. Un succès d'un AUTRE canal (carte, mobile money) n'est pas une erreur : silence.
     */
    private function calculerCommissionSiApplicable(
        ?string $paiementId,
        ?string $statut,
        ?string $canal,
        ?string $etablissementRef,
        ?int $montant,
        ?int $fraisPasserelle,
        int $fraisPrestataire,
        ?string $dateTransaction,
    ): void {
        if ($statut !== PaiementStatut::SUCCESS->value || $canal !== self::CANAL_GENIUSPAY) {
            return;
        }

        if ($etablissementRef === null || $paiementId === null || $montant === null || $dateTransaction === null) {
            Log::warning('Notification GeniusPay SUCCESS incomplète : commission NON calculée.', [
                'paiementId' => $paiementId,
                'etablissementRef' => $etablissementRef,
            ]);

            return;
        }

        $structureSanitaireId = $this->resolveur->resoudre($etablissementRef);
        if ($structureSanitaireId === null) {
            Log::error('Notification GeniusPay SUCCESS : etablissementRef inconnu, commission NON calculée.', [
                'etablissementRef' => $etablissementRef,
                'paiementId' => $paiementId,
            ]);

            return;
        }

        $this->commissions->calculerEtEnregistrer([
            'referenceInternePaiement' => 'geniuspay-paiement:'.$paiementId,
            'structureSanitaireId' => $structureSanitaireId,
            'montantBrut' => $montant,
            'fraisPasserelle' => $fraisPasserelle ?? 0,
            'fraisPrestataire' => $fraisPrestataire,
            'fraisConnus' => $fraisPasserelle !== null,
            'dateTransaction' => Carbon::parse($dateTransaction),
            'regleEnLigne' => true,
        ]);
    }
}
