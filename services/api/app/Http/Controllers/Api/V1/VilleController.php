<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ville;
use App\Services\Etablissement\LocalisateurVille;
use App\Support\TypesEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Villes couvertes et localisation de l'utilisateur (P6.4b).
 *
 * PUBLIC EN LECTURE, comme le reste de l'annuaire (`/structures`, `/medicaments`, `/symptomes`) :
 * savoir quelles villes sont couvertes ne demande aucune formalité d'identité, et l'écran a besoin
 * de cette liste **avant** toute connexion pour proposer le sélecteur de repli.
 *
 * FRONTIÈRE : ce contrôleur ne décide de rien. Quelle ville contient la position, si elle affiche
 * des communes, lesquelles, l'ordre de proximité — tout vient de `LocalisateurVille` et du modèle.
 * Le mobile affiche la réponse, il ne la déduit pas.
 */
class VilleController extends Controller
{
    public function __construct(private readonly LocalisateurVille $localisateur) {}

    /**
     * GET /api/v1/villes — les villes couvertes.
     *
     * Sert le sélecteur de repli quand l'utilisateur refuse la localisation (décision V5), d'où
     * l'ordre métier (`ordre`) et non alphabétique.
     */
    public function index(): JsonResponse
    {
        $villes = Ville::query()->active()->orderBy('ordre')->orderBy('nom')->get()
            ->map(fn (Ville $v): array => [
                'code'             => $v->code,
                'nom'              => $v->nom,
                'latitude'         => $v->latitude,
                'longitude'        => $v->longitude,
                'affiche_communes' => $v->affiche_communes,
                'communes'         => $v->communes(),
            ]);

        return response()->json([
            'villes' => $villes,
            // Livré ici plutôt que dans un endpoint séparé : l'écran charge les villes au
            // démarrage, et il a besoin des libellés de catégorie au même moment. Les recopier
            // côté mobile était le défaut G-a du plan — `LIBELLE_TYPE` n'y connaissait que 7 des
            // 13 catégories et aurait affiché « undefined » devant une structure d'un type neuf.
            'types_etablissement' => TypesEtablissement::pourApi(),
        ]);
    }

    /**
     * GET /api/v1/villes/localiser?lat=&lng= — « où suis-je ? ».
     *
     * Réponse complète et sans ambiguïté pour l'écran :
     *   · `ville` non nul   → « Vous êtes à X », et `communes` dit s'il faut afficher des filtres ;
     *   · `hors_zone` vrai  → « hors des zones couvertes », et `villes_par_proximite` donne
     *     l'ordre dans lequel présenter les structures (décision V6).
     *
     * On ne rattache JAMAIS à la ville la plus proche quand aucune ne contient la position : un
     * utilisateur à Man serait déclaré « à Bouaké », à 300 km.
     */
    public function localiser(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $resultat = $this->localisateur->localiser((float) $valide['lat'], (float) $valide['lng']);
        $ville = $resultat['ville'];

        return response()->json([
            'ville' => $ville === null ? null : [
                'code'             => $ville->code,
                'nom'              => $ville->nom,
                'affiche_communes' => $ville->affiche_communes,
            ],
            'hors_zone'            => $resultat['hors_zone'],
            'communes'             => $resultat['communes'],
            'villes_par_proximite' => $resultat['villes_par_proximite'],
        ]);
    }
}
