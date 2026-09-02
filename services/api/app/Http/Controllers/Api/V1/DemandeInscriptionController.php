<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DemandeInscriptionEtablissement;
use App\Services\ServiceDemandeInscription;
use App\Support\TypesEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * P11.1 — Dépôt PUBLIC d'une demande d'inscription (CDC_11 §3, méthode 2).
 *
 * « Clinique Saint Joseph souhaite rejoindre la plateforme » : un établissement qui n'a ni compte
 * ni contact préalable doit pouvoir se faire connaître. Cet endpoint est donc **sans
 * authentification** — c'est le point de la méthode 2, et l'authentifier reviendrait à la méthode 1.
 *
 * Trois gardes, aucune ne remplaçant les autres : le **limiteur de requêtes** de la route, la
 * règle « une seule demande en attente par adresse » du service, et le fait qu'**aucune donnée
 * déposée ici n'atteint `structures_sanitaires`** avant qu'un humain habilité n'approuve.
 */
class DemandeInscriptionController extends Controller
{
    public function __construct(
        private readonly ServiceDemandeInscription $service,
    ) {}

    /** Dépose une candidature et rend sa référence de suivi. */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            // L'établissement candidat
            'nom' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', Rule::in(TypesEtablissement::codes())],
            'statut_juridique' => ['nullable', 'string', 'max:40'],
            // Ce qui rend la demande vérifiable : la plateforme confrontera ce numéro à
            // l'autorité de tutelle. Sans lui, il n'y a rien à vérifier et la demande ne peut
            // qu'être crue sur parole.
            'numero_autorisation' => ['required', 'string', 'max:60'],
            'adresse' => ['required', 'string', 'max:255'],
            'commune' => ['nullable', 'string', 'max:100'],
            'telephone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:190'],

            // La personne qui répond de la demande — distincte du standard de l'établissement.
            'demandeur_nom' => ['required', 'string', 'max:100'],
            'demandeur_prenom' => ['required', 'string', 'max:100'],
            'demandeur_fonction' => ['required', 'string', 'max:120'],
            'demandeur_email' => ['required', 'email', 'max:190'],
            'demandeur_telephone' => ['required', 'string', 'max:20'],

            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $demande = $this->service->deposer($donnees);

        return response()->json([
            'reference' => $demande->reference,
            'statut' => $demande->statut,
            'message' => 'Votre demande a été enregistrée. Conservez cette référence : elle vous '
                .'permettra d’en suivre l’avancement.',
        ], 201);
    }

    /**
     * Suivi d'une demande par sa référence.
     *
     * On ne rend QUE l'état de la décision, jamais le contenu déposé. La référence est opaque et
     * non séquentielle, donc non énumérable — mais l'exposer ne doit pas non plus devenir un
     * moyen de relire les coordonnées d'un établissement candidat pour qui l'aurait interceptée.
     *
     * **404 et jamais 403** sur une référence inconnue : un 403 confirmerait qu'une demande
     * existe à cet identifiant (précédent de la fiche de triage, P10a, et de l'anti-IDOR de P7-D1).
     */
    public function suivi(string $reference): JsonResponse
    {
        $demande = DemandeInscriptionEtablissement::query()
            ->where('reference', $reference)
            ->firstOrFail();

        return response()->json([
            'reference' => $demande->reference,
            'statut' => $demande->statut,
            'decide_le' => $demande->decide_le,
            'motif_rejet' => $demande->motif_rejet,
        ]);
    }
}
