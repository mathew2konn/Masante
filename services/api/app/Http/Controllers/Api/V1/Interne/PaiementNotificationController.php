<?php

namespace App\Http\Controllers\Api\V1\Interne;

use App\Exceptions\PrincipalSigneInvalideException;
use App\Http\Controllers\Controller;
use App\Services\ClientPaiementGeniusPay;
use App\Services\CommissionService;
use App\Services\Medicament\ServiceCommande;
use App\Services\RecuRdvService;
use App\Services\ResolveurEtablissementRef;
use App\Services\VerificateurPrincipalSigne;
use App\Support\PaiementStatut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notification entrante paiement-service (Java) → Laravel — lot 6 (v2), volet 1 + B4-a/B4-b/B3-d.
 *
 * ═══ CE CONTRÔLEUR TRANSPORTE, PUIS DISPATCHE À TROIS CONCERNS INDÉPENDANTS ═══
 * (1) le calcul de commission (B4-a, ci-dessous) ; (2) depuis B4-b, le règlement d'une
 * `FacturePatient` dont le `correlationId` la désigne ({@see reglerFacturePatientSiApplicable()}) ;
 * (3) depuis B3-d, le règlement d'une `Commande` dont le `correlationId` la désigne ({@see
 * reglerCommandeSiApplicable()}) — JAMAIS la même table que (2), une commande de médicaments
 * n'étant pas un acte au sens de `factures_patient`. Les trois partagent la même garde
 * canal/statut mais sont INDÉPENDANTS : un paiement peut régler une facture ou une commande sans
 * jamais avoir d'`etablissementRef` résoluble (commission alors journalisée en échec, le
 * règlement effectué quand même), et réciproquement — **et l'indépendance est désormais réelle,
 * pas seulement déclarée** : le calcul de commission est encapsulé en `try/catch` (B3-d, voir
 * `__invoke()`), sans quoi une exception y aurait empêché les deux règlements de s'exécuter dans
 * le même appel malgré ce paragraphe.
 *
 * ═══ CE CONTRÔLEUR DÉCLENCHE UNE COMMISSION SUR LE SEUL CANAL GENIUSPAY ═══
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
        private readonly RecuRdvService $recus,
        private readonly ServiceCommande $commandes,
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

        // B3-d (F6, G0) — DÉFAUT RÉEL TROUVÉ EN RELISANT CE FICHIER AVANT D'Y BRANCHER UN
        // TROISIÈME DISPATCH : sans ce `try/catch`, une exception ici (barème de commission
        // manquant, montant invalide) arrêtait TOUT `__invoke()` et empêchait les règlements
        // ci-dessous de s'exécuter dans le MÊME appel — alors que le docblock de cette classe
        // affirme depuis B4-b que les dispatches sont INDÉPENDANTS. Vrai pour les GARDES (`if`
        // qui rendent tôt), faux pour une exception non gardée. La commission peut désormais
        // échouer sans jamais empêcher un patient d'être livré ou un rendez-vous d'être honoré.
        try {
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
        } catch (Throwable $e) {
            Log::error('Notification paiement-service : calcul de commission en échec, réglement non bloqué.', [
                'paiementId' => $paiementId,
                'motif' => $e->getMessage(),
            ]);
        }

        $correlationId = $request->input('correlationId');
        $this->reglerFacturePatientSiApplicable(
            correlationId: is_string($correlationId) ? $correlationId : null,
            paiementId: is_string($paiementId) ? $paiementId : null,
            statut: is_string($statut) ? $statut : null,
            canal: is_string($canal) ? $canal : null,
            dateTransaction: is_string($dateTransaction) ? $dateTransaction : null,
        );

        $this->reglerCommandeSiApplicable(
            correlationId: is_string($correlationId) ? $correlationId : null,
            paiementId: is_string($paiementId) ? $paiementId : null,
            statut: is_string($statut) ? $statut : null,
            canal: is_string($canal) ? $canal : null,
            dateTransaction: is_string($dateTransaction) ? $dateTransaction : null,
        );

        return response()->json([], 200);
    }

    /**
     * B4-b (S6, S12) — règle une `FacturePatient` (aujourd'hui : un rendez-vous) sur un succès
     * GeniusPay dont le `correlationId` la désigne. Même garde canal/statut que la commission
     * ci-dessus, et pour la MÊME raison (R2') : `etablissementRef` seul ne discrimine rien, la
     * carte et le mobile money le portent aussi.
     *
     * Silencieux hors du périmètre `facture-patient:` — un `correlationId` d'une autre origine
     * (ou absent) n'est jamais une erreur ici, seulement une notification qui ne concerne pas ce
     * traitement. Toute la logique de règlement (idempotence, verrou) vit dans
     * {@see RecuRdvService::confirmerReglementEnLigne()} — ce contrôleur reste un DISPATCHER.
     */
    private function reglerFacturePatientSiApplicable(
        ?string $correlationId,
        ?string $paiementId,
        ?string $statut,
        ?string $canal,
        ?string $dateTransaction,
    ): void {
        if ($statut !== PaiementStatut::SUCCESS->value || $canal !== self::CANAL_GENIUSPAY) {
            return;
        }

        $facturePatientId = $this->recus->facturePatientIdDepuisCorrelation($correlationId);
        if ($facturePatientId === null || $paiementId === null || $dateTransaction === null) {
            return;
        }

        $this->recus->confirmerReglementEnLigne($facturePatientId, $paiementId, $dateTransaction);
    }

    /**
     * B3-d (F6, réécrit après B4) — règle une `Commande` sur un succès GeniusPay dont le
     * `correlationId` la désigne. PARALLÈLE à {@see reglerFacturePatientSiApplicable()}, JAMAIS
     * la même table : une commande de médicaments n'est pas un acte au sens de `factures_patient`
     * (table de facturation DE SOINS — CMU, reste à charge), elle porte son propre règlement.
     *
     * Silencieux hors du périmètre `commande:` — même garde que le dispatch RDV/facture.
     */
    private function reglerCommandeSiApplicable(
        ?string $correlationId,
        ?string $paiementId,
        ?string $statut,
        ?string $canal,
        ?string $dateTransaction,
    ): void {
        if ($statut !== PaiementStatut::SUCCESS->value || $canal !== self::CANAL_GENIUSPAY) {
            return;
        }

        $commandeId = $this->commandes->commandeIdDepuisCorrelation($correlationId);
        if ($commandeId === null || $paiementId === null || $dateTransaction === null) {
            return;
        }

        $this->commandes->confirmerReglementEnLigne($commandeId, $paiementId, $dateTransaction);
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
