<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Vaccin;
use App\Services\Vaccin\ServiceCalendrierVaccinal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P6.8b — Vaccins disponibles et calendrier vaccinal national (CDC_09 §8).
 *
 * PUBLIC EN LECTURE, comme le reste des référentiels d'annuaire : savoir quels vaccins existent et
 * à quel âge ils sont prévus est une information de santé publique, et l'exiger authentifiée
 * priverait de la consulter précisément ceux qui n'ont pas encore de compte.
 *
 * ═══ CE QU'IL SERT, ET D'OÙ ═══
 *
 * De la **version publiée** du référentiel, jamais de la table
 * ({@see App\Services\Vaccin\ServiceCalendrierVaccinal}). Un `UPDATE` direct sur `vaccins` n'a donc
 * aucun effet ici avant publication — c'est le but du §1.2.4, et la leçon de L1+L2.
 *
 * FRONTIÈRE : ce contrôleur ne conclut rien. Il ne dit pas si une personne doit être vaccinée, ne
 * compare aucun carnet, ne recommande rien — il expose un calendrier et la phrase qui dit qu'il ne
 * remplace pas un professionnel de santé.
 */
class VaccinController extends Controller
{
    public function __construct(private readonly ServiceCalendrierVaccinal $calendrier) {}

    /**
     * GET /api/v1/vaccins — le référentiel en vigueur, avec son calendrier.
     *
     * `?q=` filtre sur le libellé et l'abréviation : c'est ce que sert le champ de saisie du
     * carnet, où le parent tape « penta » et non « Vaccin pentavalent DTC-HepB-Hib ».
     */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $recherche = mb_strtolower(trim((string) ($filtres['q'] ?? '')));

        // ═══ DEUX LECTURES, COMME DANS LE SERVICE DE LIEN ═══
        //
        // Le CONTENU vient de la version publiée — c'est elle qui fait autorité. L'IDENTIFIANT
        // TECHNIQUE vient de la table : `vaccinations.vaccin_id` est une clé étrangère, et un
        // instantané ne porte que des codes nationaux (délibérément : il doit rester lisible quand
        // les identifiants d'une base ne sont plus ceux d'une autre).
        //
        // Résoudre une identité n'est pas lire un contenu : un `UPDATE` direct sur `age_jours_du`
        // reste sans effet ici, ce qui est le point de la bascule.
        $identifiants = Vaccin::query()
            ->where('pays_code', config('referentiels.pays_defaut', 'CI'))
            ->whereNotNull('code')
            ->pluck('id', 'code');

        $vaccins = collect($this->contenu())
            ->filter(fn (array $v): bool => (bool) ($v['actif'] ?? false))
            ->filter(function (array $v) use ($recherche): bool {
                if ($recherche === '') {
                    return true;
                }

                return str_contains(mb_strtolower((string) $v['libelle']), $recherche)
                    || str_contains(mb_strtolower((string) ($v['abreviation'] ?? '')), $recherche);
            })
            // Un vaccin publié dont l'identifiant n'existe plus en base ne peut plus être rattaché :
            // on ne le sert pas plutôt que de renvoyer un `id` nul qui échouerait à l'écriture.
            ->filter(fn (array $v): bool => isset($identifiants[$v['code']]))
            ->map(fn (array $v): array => [
                'id'                  => $identifiants[$v['code']],
                'code'                => $v['code'],
                'libelle'             => $v['libelle'],
                'abreviation'         => $v['abreviation'] ?? null,
                'maladies_evitees'    => $v['maladies_evitees'] ?? null,
                'voie_administration' => $v['voie_administration'] ?? null,
                'nb_doses'            => (int) ($v['nb_doses'] ?? 1),
                'statut_marche'       => $v['statut_marche'] ?? null,
                // Les doses, pour que l'écran propose « dose 1 / 2 / 3 » sans inventer le nombre.
                'doses'               => collect($v['echeances'] ?? [])
                    ->map(fn (array $e): array => [
                        'numero_dose'      => (int) $e['numero_dose'],
                        'libelle_echeance' => $e['libelle_echeance'] ?? null,
                        'obligatoire'      => (bool) ($e['obligatoire'] ?? false),
                        'de_demonstration' => ($e['source'] ?? null) === 'demonstration',
                    ])
                    ->values(),
            ])
            ->values();

        return response()->json([
            'vaccins' => $vaccins,
            // §10 « toute décision conserve la version du référentiel utilisée » : la réponse dit
            // sous quelle version elle a été établie, comme le fait `/medicaments/interactions`.
            'version' => $this->calendrier->version(),
            'limites' => 'Ce calendrier est une information de santé publique. Il ne remplace pas '
                .'l\'avis d\'un professionnel de santé et ne tient compte d\'aucune situation '
                .'individuelle (contre-indication, antécédent, vaccination faite à l\'étranger).',
        ]);
    }

    /**
     * Le contenu publié — la lecture qui lève 503 si aucune version n'est en vigueur.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contenu(): array
    {
        // `version()` déclenche le chargement, donc le refus bruyant, avant qu'on ne compose une
        // réponse vide qui aurait ressemblé à « aucun vaccin n'existe ».
        $this->calendrier->version();

        return $this->calendrier->contenuPublie();
    }
}
