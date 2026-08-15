<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganismeAssurance;
use App\Services\Assurance\ServiceAssurances;
use App\Support\TypesOrganismeAssurance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P6.8d — Registre national des organismes d'assurance agréés (CDC_09 §8).
 *
 * PUBLIC EN LECTURE, comme `/vaccins`, `/analyses`, `/medicaments`, `/specialites` et `/maladies` :
 * savoir quels organismes sont recensés n'est pas une donnée personnelle, et l'exiger authentifié en
 * priverait précisément ceux qui n'ont pas encore de compte — alors que c'est au moment de créer son
 * dossier qu'on cherche le nom de sa mutuelle.
 *
 * ═══ CE QU'IL SERT, ET D'OÙ ═══
 *
 * De la **version publiée**, jamais de la table ({@see ServiceAssurances}). Un `UPDATE` direct sur
 * `organismes_assurance` n'a donc aucun effet ici avant publication — c'est le but du §1.2.4, et la
 * leçon de L1+L2. **503 tant qu'aucune version n'est en vigueur** : un repli sur la table laisserait
 * un oubli de publication invisible.
 *
 * ═══ LES LIBELLÉS DE FAMILLE SONT SERVIS ICI ═══
 *
 * Quatrième récidive évitée du constat G-a de P6.4b : ils ne sont recopiés dans aucun client.
 *
 * ═══ CE QUE CETTE LISTE NE PROUVE PAS ═══
 *
 * Qu'un organisme est agréé. Aucun numéro d'agrément n'a été chargé dans ce projet et le contenu
 * livré est un jeu de démonstration : la réponse porte l'avertissement, elle ne le laisse pas
 * deviner.
 */
class AssuranceController extends Controller
{
    public function __construct(private readonly ServiceAssurances $assurances) {}

    /**
     * GET /api/v1/assurances — le registre en vigueur.
     *
     * `?q=` cherche dans le nom et le sigle, sans accent ni casse. `?type=` filtre sur l'une des six
     * familles du §8.2. `?pays=` choisit le pays : un agrément est national (à la différence d'une
     * maladie, décision E2 de P6.8c).
     */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'q'    => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', TypesOrganismeAssurance::regleIn()],
            'pays' => ['nullable', 'string', 'size:2'],
        ]);

        // Déclenche le chargement, donc le refus bruyant, AVANT de composer une réponse vide qui
        // aurait ressemblé à « aucun organisme n'existe » (motif P6.8b).
        $version = $this->assurances->version();
        $pays    = strtoupper((string) ($filtres['pays'] ?? config('referentiels.pays_defaut', 'CI')));

        // ═══ DEUX LECTURES, CHACUNE POUR UNE QUESTION DIFFÉRENTE (motif P6.8b/P6.8c) ═══
        //
        // Le CONTENU vient de la version publiée — c'est elle qui fait autorité. L'IDENTIFIANT
        // TECHNIQUE vient de la table : `couvertures_membre.organisme_assurance_id` est une clé
        // étrangère, et un instantané ne porte que des codes nationaux (délibérément : il doit
        // rester lisible quand les identifiants d'une base ne sont plus ceux d'une autre).
        $identifiants = OrganismeAssurance::query()
            ->where('pays_code', $pays)
            ->whereNotNull('code')
            ->pluck('id', 'code');

        $liste = collect($this->assurances->rechercher((string) ($filtres['q'] ?? ''), $pays, 100))
            ->when(
                isset($filtres['type']),
                fn ($c) => $c->filter(fn (array $o): bool => $o['type'] === $filtres['type']),
            )
            // Un organisme publié dont l'identifiant n'existe plus en base ne peut plus être
            // rattaché : on ne le sert pas plutôt que de renvoyer un `id` nul qui échouerait à
            // l'écriture (motif P6.8b).
            ->filter(fn (array $o): bool => isset($identifiants[$o['code']]))
            ->map(fn (array $o): array => [
                'id'               => $identifiants[$o['code']],
                'code'             => $o['code'],
                'nom'              => $o['nom'],
                'sigle'            => $o['sigle'],
                'type'             => $o['type'],
                'type_libelle'     => $o['type_libelle'],
                'agrement_statut'  => $o['agrement_statut'],
                'agrement_fin'     => $o['agrement_fin'],
                // Vide, et c'est dit (motif `loinc` P6.7a, `code_cim10` P6.8c).
                'numero_agrement'  => $o['numero_agrement'],
                'de_demonstration' => $o['de_demonstration'],
            ])
            ->values();

        return response()->json([
            'organismes' => $liste,
            // SOURCE UNIQUE des libellés de famille : le mobile les consomme, il ne les recopie pas.
            'types'      => TypesOrganismeAssurance::pourApi(),
            'pays'       => $pays,
            // §10 « toute décision conserve la version du référentiel utilisée ».
            'version'    => $version,
            'avertissement' => $this->assurances->avertissementDemonstration($pays),
            'limites'    => 'Ce registre recense des organismes ; il ne prouve pas qu\'ils sont '
                .'agréés, ne dit rien de ce que couvre un contrat, et ne calcule aucun '
                .'remboursement.',
        ]);
    }
}
