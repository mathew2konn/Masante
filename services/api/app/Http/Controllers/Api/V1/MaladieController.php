<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Maladie;
use App\Services\Maladie\ServiceMaladies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P6.8c — Référentiel national des maladies (CDC_09 §8).
 *
 * PUBLIC EN LECTURE, comme `/vaccins`, `/analyses`, `/medicaments` et `/specialites` : savoir quelles
 * maladies le pays surveille est une information de santé publique, et l'exiger authentifiée
 * priverait de la consulter précisément ceux qui n'ont pas encore de compte. Aucune donnée
 * personnelle n'y figure.
 *
 * ═══ CE QU'IL SERT, ET D'OÙ ═══
 *
 * De la **version publiée**, jamais de la table ({@see ServiceMaladies}). Un `UPDATE` direct sur
 * `maladies` n'a donc aucun effet ici avant publication — c'est le but du §1.2.4, et la leçon de
 * L1+L2. **503 tant qu'aucune version n'est en vigueur** : un repli sur la table laisserait un oubli
 * de publication invisible.
 *
 * ═══ FRONTIÈRE ═══
 *
 * Ce contrôleur ne conclut RIEN. Il ne dit pas de quoi souffre qui que ce soit, ne rapproche aucun
 * symptôme d'aucune maladie, ne pose aucun diagnostic — il expose un vocabulaire. La recherche `?q=`
 * compare des chaînes normalisées ; elle ne mesure aucune distance et ne « devine » aucune maladie
 * (CDC_00 §4).
 */
class MaladieController extends Controller
{
    public function __construct(private readonly ServiceMaladies $maladies) {}

    /**
     * GET /api/v1/maladies — le référentiel en vigueur.
     *
     * `?q=` cherche dans le libellé officiel ET dans les libellés alternatifs : c'est le service que
     * rend le multilingue du §8 — « palu » retrouve « Paludisme ».
     * `?pays=` choisit le pays dont on lit la surveillance ; la maladie, elle, n'en a aucun (E2).
     */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'q'    => ['nullable', 'string', 'max:120'],
            'pays' => ['nullable', 'string', 'size:2'],
        ]);

        // Déclenche le chargement, donc le refus bruyant, AVANT de composer une réponse vide qui
        // aurait ressemblé à « aucune maladie n'existe » (motif P6.8b).
        $version = $this->maladies->version();
        $pays    = strtoupper((string) ($filtres['pays'] ?? config('referentiels.pays_defaut', 'CI')));

        // ═══ DEUX LECTURES, COMME DANS LE SERVICE DE LIEN ═══
        //
        // Le CONTENU vient de la version publiée — c'est elle qui fait autorité. L'IDENTIFIANT
        // TECHNIQUE vient de la table : `antecedents.maladie_id` est une clé étrangère, et un
        // instantané ne porte que des codes nationaux (délibérément : il doit rester lisible quand
        // les identifiants d'une base ne sont plus ceux d'une autre). Résoudre une identité n'est
        // pas lire un contenu.
        $identifiants = Maladie::query()->whereNotNull('code')->pluck('id', 'code');

        $liste = collect($this->maladies->rechercher((string) ($filtres['q'] ?? ''), $pays, 100))
            // Une maladie publiée dont l'identifiant n'existe plus en base ne peut plus être
            // rattachée : on ne la sert pas plutôt que de renvoyer un `id` nul qui échouerait à
            // l'écriture (motif P6.8b).
            ->filter(fn (array $m): bool => isset($identifiants[$m['code']]))
            ->map(fn (array $m): array => [
                'id'                       => $identifiants[$m['code']],
                'code'                     => $m['code'],
                'libelle'                  => $m['libelle'],
                // Vides, et c'est dit : CIM-10 et CIM-11 sont des publications de l'OMS que ce
                // projet n'a pas chargées (motif `analyses.loinc`, P6.7a).
                'code_cim10'               => $m['code_cim10'],
                'code_cim11'               => $m['code_cim11'],
                'description'              => $m['description'],
                'surveillance_prioritaire' => $m['surveillance_prioritaire'],
                'declaration_obligatoire'  => $m['declaration_obligatoire'],
                'de_demonstration'         => $m['de_demonstration'],
                // Les autres façons de la nommer — ce que le §8 appelle « libellés multilingues ».
                'libelles'                 => collect($m['libelles'])
                    ->map(fn (array $l): array => [
                        'langue'    => $l['langue'],
                        'libelle'   => $l['libelle'],
                        'principal' => (bool) ($l['principal'] ?? false),
                    ])
                    ->values(),
            ])
            ->values();

        return response()->json([
            'maladies' => $liste,
            'pays'     => $pays,
            // §10 « toute décision conserve la version du référentiel utilisée ».
            'version'  => $version,
            'avertissement' => $this->maladies->avertissementDemonstration($pays),
            'limites'  => 'Ce référentiel est un vocabulaire. Il ne pose aucun diagnostic, ne '
                .'rapproche aucun symptôme d\'aucune maladie, et ne remplace pas l\'avis d\'un '
                .'professionnel de santé.',
        ]);
    }
}
