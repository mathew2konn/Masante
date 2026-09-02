<?php

namespace App\Http\Controllers\Api\V1\Interne;

use App\Exceptions\PrincipalSigneInvalideException;
use App\Http\Controllers\Controller;
use App\Services\VerificateurPrincipalSigne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notification entrante paiement-service (Java) → Laravel — lot 6 (v2), volet 1.
 *
 * ═══ CE CONTRÔLEUR TRANSPORTE, IL NE DÉCLENCHE RIEN ═══
 * La v1 de ce lot appelait `CommissionService::calculerEtEnregistrer()` ici. L'audit Phase 0 du
 * volet Java a établi que le payload ne peut pas porter ce qu'il faut pour l'appeler correctement :
 * `commissions_transaction.structure_sanitaire_id` est NOT NULL, or ni `Paiement.factureId` (UUID
 * d'une facture INTERNE au microservice, P5.2a) ni `correlationId` (chaîne libre) ne portent
 * d'identifiant de structure MaSanté. Appeler quand même écrirait une commission rattachée à la
 * mauvaise structure, ou planterait sur la contrainte — deux façons de faire pire que ne rien faire.
 *
 * Ce n'est pas un oubli du service Java : en montage A, la structure ne devient nécessaire que pour
 * choisir la bonne clé marchande, besoin qui n'existe pas tant que GeniusPay n'est pas branché.
 *
 * L'endpoint vérifie donc l'authenticité de l'appel, journalise, et répond 200. Le déclenchement
 * métier est l'affaire du lot 7 — une EXTENSION de ce point d'accroche, pas une reconstruction.
 *
 * Authentifié par principal signé (canal interne, `VerificateurPrincipalSigne`), JAMAIS par Sanctum :
 * cet appelant n'a pas de session utilisateur.
 */
class PaiementNotificationController extends Controller
{
    public function __construct(
        private readonly VerificateurPrincipalSigne $verificateur,
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

        // Le contrat d'entrée n'exige QUE ces quatre champs. `fraisPasserelle`/`fraisPrestataire`
        // arrivent à 0 (paiement simulé, aucune passerelle réelle — ce n'est pas une valeur inventée,
        // c'est le coût réel d'une passerelle qui n'existe pas encore) et `facturePatientId` n'a
        // aucun porteur côté Java : exiger l'un ou l'autre ferait échouer un appel parfaitement valide.
        Log::info('Notification paiement-service reçue et vérifiée.', [
            'correlationId' => $request->input('correlationId'),
            'montant' => $request->input('montant'),
            'statut' => $request->input('statut'),
            'dateTransaction' => $request->input('dateTransaction'),
            'recu_le' => now()->toIso8601String(),
        ]);

        // TODO lot 7 : une fois structureSanitaireId disponible dans le payload, appeler
        // CommissionService::calculerEtEnregistrer() ici.

        return response()->json([], 200);
    }
}
