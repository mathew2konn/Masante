<?php

namespace App\Http\Controllers\Api\V1\Portail;

use App\Http\Controllers\Controller;
use App\Models\DemandeInscriptionEtablissement;
use App\Services\ServiceDemandeInscription;
use App\Support\TypesEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * P11.1 — Traitement des candidatures par l'équipe plateforme (CDC_11 §3, méthode 2).
 *
 * ═══ PREMIÈRE ZONE MÉTIER DU PORTAIL NEXT ═══
 *
 * C'est la première application qui se branche sur le socle de P11.0, et elle en éprouve le
 * registre de zones : `demandes-inscription` y déclare `etablissement.manage`, et la même
 * déclaration garde la page et alimente la navigation.
 *
 * ═══ AUCUNE PERMISSION NEUVE ═══
 *
 * `etablissement.manage` existe déjà et couvre exactement cet acte : approuver une candidature,
 * c'est créer un établissement. En inventer une (`demande.traiter`) aurait donné deux clés pour
 * une seule porte, et laissé la question « qui a le droit de créer un établissement ? » avoir
 * deux réponses.
 *
 * ═══ LA PERMISSION EST VÉRIFIÉE ICI, PAS PAR LE MIDDLEWARE ═══
 *
 * Ces routes sont authentifiées par **Sanctum** alors que les permissions vivent sur le guard
 * `web` : le middleware `permission:` de spatie viserait le mauvais guard et laisserait passer.
 * C'est le piège rencontré en P4 sur `rdv.validate`, et la convention du groupe `portail`.
 */
class DemandeInscriptionController extends Controller
{
    public function __construct(
        private readonly ServiceDemandeInscription $service,
    ) {}

    /** File des candidatures, filtrable par état. */
    public function index(Request $request): JsonResponse
    {
        $this->exigerHabilitation($request);

        $statut = $request->query('statut');
        $statuts = [
            DemandeInscriptionEtablissement::EN_ATTENTE,
            DemandeInscriptionEtablissement::APPROUVEE,
            DemandeInscriptionEtablissement::REJETEE,
        ];

        $demandes = DemandeInscriptionEtablissement::query()
            ->when(in_array($statut, $statuts, true), fn ($q) => $q->where('statut', $statut))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['demandes' => $demandes]);
    }

    public function show(Request $request, DemandeInscriptionEtablissement $demande): JsonResponse
    {
        $this->exigerHabilitation($request);

        return response()->json(['demande' => $demande]);
    }

    /**
     * Approuve la candidature : l'établissement naît par le MÊME chemin que la méthode 1.
     *
     * L'agent complète ce que le formulaire public ne demandait pas — les coordonnées GPS, qui
     * ne se déclarent pas de confiance : elles placent l'établissement sur la carte que lira un
     * patient qui cherche où aller (P6.4b). Le reste des trente champs sera renseigné par
     * l'établissement lui-même après activation, comme CDC_11 §3 le prévoit.
     */
    public function approuver(Request $request, DemandeInscriptionEtablissement $demande): JsonResponse
    {
        $this->exigerHabilitation($request);

        $valide = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'type' => ['nullable', 'string', Rule::in(TypesEtablissement::codes())],
            'gestionnaire_nom' => ['required', 'string', 'max:100'],
            'gestionnaire_prenom' => ['required', 'string', 'max:100'],
            'gestionnaire_email' => ['required', 'email', 'max:190'],
        ]);

        // Les données de la CANDIDATURE font foi pour tout ce qu'elle portait : l'agent vérifie,
        // il ne ressaisit pas. Seul `type` peut être rectifié — c'est le champ qu'un demandeur
        // se trompe le plus souvent (« clinique » pour un cabinet), et le laisser faux
        // fausserait durablement les statistiques du §4.4.
        try {
            $lien = $this->service->approuver(
                $demande,
                [
                    'nom' => $demande->nom,
                    'type' => $valide['type'] ?? $demande->type,
                    'statut_juridique' => $demande->statut_juridique,
                    'numero_autorisation' => $demande->numero_autorisation,
                    'adresse' => $demande->adresse,
                    'commune' => $demande->commune ?? '—',
                    'telephone' => $demande->telephone,
                    'email' => $demande->email,
                    'latitude' => $valide['latitude'],
                    'longitude' => $valide['longitude'],
                ],
                [
                    'nom' => $valide['gestionnaire_nom'],
                    'prenom' => $valide['gestionnaire_prenom'],
                    'email' => $valide['gestionnaire_email'],
                ],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            // 409 et non 403 : l'agent a le droit de décider, c'est CETTE demande qui ne l'est
            // plus. Confondre les deux ferait croire à un défaut d'habilitation (précédent P7-C).
            abort(Response::HTTP_CONFLICT, $e->getMessage());
        }

        return response()->json([
            'statut' => DemandeInscriptionEtablissement::APPROUVEE,
            'lien_activation' => $lien,
            'message' => 'Établissement créé. Transmettez le lien d’activation au gestionnaire.',
        ]);
    }

    /** Rejette la candidature. Le motif est obligatoire — le demandeur le lira. */
    public function rejeter(Request $request, DemandeInscriptionEtablissement $demande): JsonResponse
    {
        $this->exigerHabilitation($request);

        $valide = $request->validate([
            'motif_rejet' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $rejetee = $this->service->rejeter($demande, $valide['motif_rejet'], $request->user());
        } catch (RuntimeException $e) {
            abort(Response::HTTP_CONFLICT, $e->getMessage());
        }

        return response()->json(['statut' => $rejetee->statut, 'motif_rejet' => $rejetee->motif_rejet]);
    }

    private function exigerHabilitation(Request $request): void
    {
        abort_unless(
            $request->user()?->can('etablissement.manage') === true,
            Response::HTTP_FORBIDDEN,
            'Le traitement des demandes d’inscription est réservé à l’administration de la plateforme.',
        );
    }
}
