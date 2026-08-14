<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Analyse;
use App\Models\AnalyseReference;
use App\Services\Analyse\ReglesIntervalleReference;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\SourceAnalyses;
use App\Support\Analyses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * P6.7a — Le catalogue national des analyses, en lecture (CDC_09 §7.3).
 *
 * PUBLIC comme le reste des référentiels d'annuaire : savoir ce qu'est une analyse et dans quelle
 * plage elle se situe habituellement ne demande aucune identité, et une valeur de référence n'a
 * d'utilité que largement diffusée.
 *
 * ═══ CE CONTRÔLEUR NE CONCLUT JAMAIS ═══
 *
 * Il sert une analyse et les strates de référence qui s'appliquent à un âge et un sexe donnés. Il ne
 * compare aucun résultat, ne renvoie aucun statut « normal » ou « anormal ». C'est la décision du
 * propriétaire, et elle tient à ce que §7.3 ne décrit **aucune** stratification : conclure sur une
 * référence unique dirait à une femme enceinte que son hémoglobine est basse alors qu'elle est
 * normale pour elle.
 */
class AnalyseController extends Controller
{
    /** Recherche au catalogue (public), par code, libellé ou catégorie. */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'q'         => ['nullable', 'string', 'max:120'],
            'categorie' => ['nullable', Rule::in(Analyses::categories())],
        ]);

        $analyses = Analyse::query()
            ->where('actif', true)
            ->when($filtres['q'] ?? null, fn ($q, $terme) => $q->where(
                fn ($sous) => $sous->where('libelle', 'like', "%{$terme}%")
                    ->orWhere('code', 'like', "%{$terme}%")
            ))
            ->when($filtres['categorie'] ?? null, fn ($q, $c) => $q->where('categorie', $c))
            ->orderBy('libelle')
            ->limit(50)
            ->get();

        return response()->json([
            'analyses'     => $analyses,
            // Servies par le serveur, jamais recopiées par un client — défaut de P6.4b.
            'enumerations' => Analyses::pourApi(),
        ]);
    }

    /**
     * Les valeurs de référence d'une analyse, pour un âge et un sexe donnés (§7.3).
     *
     * ═══ PLUSIEURS STRATES PEUVENT ÊTRE RENVOYÉES, ET C'EST LE POINT ═══
     *
     * Une femme adulte reçoit sa strate standard **et** les strates de grossesse, marquées
     * `conditionnelle`. La plateforme ne choisit pas : le carnet connaît pourtant la grossesse, mais
     * décider à la place de la patiente laquelle de ces plages la concerne serait un jugement
     * clinique. Le lecteur voit celle qui le concerne — motif des trois silences de P7-D2.
     *
     * L'âge se donne en JOURS : la période néonatale se compte ainsi, et plusieurs paramètres
     * changent dans la première semaine de vie.
     */
    public function references(Request $request, Analyse $analyse, ReglesIntervalleReference $regles, DiffusionReferentiel $diffusion): JsonResponse
    {
        $filtres = $request->validate([
            'age_jours' => ['nullable', 'integer', 'min:0', 'max:45000'],
            'sexe'      => ['nullable', Rule::in(['M', 'F'])],
        ]);

        $strates = $analyse->references()
            ->orderBy('etat_physiologique')
            ->orderBy('age_min_jours')
            ->get()
            ->map(fn (AnalyseReference $r): array => [
                'sexe'               => $r->sexe,
                'age_min_jours'      => $r->age_min_jours,
                'age_max_jours'      => $r->age_max_jours,
                'etat_physiologique' => $r->etat_physiologique,
                'etat_libelle'       => Analyses::libelleEtat($r->etat_physiologique),
                'valeur_min'         => $r->valeur_min,
                'valeur_max'         => $r->valeur_max,
                'plage'              => $r->plageLisible(),
                'critique_bas'       => $r->critique_bas,
                'critique_haut'      => $r->critique_haut,
                'libelle_strate'     => $r->libelle_strate,
                'source'             => $r->source,
                'source_libelle'     => Analyses::libelleSource($r->source),
                'source_detail'      => $r->source_detail,
            ])
            ->all();

        $applicables = $regles->applicables($strates, $filtres['sexe'] ?? null, $filtres['age_jours'] ?? null);

        return response()->json([
            'analyse'     => [
                'code'    => $analyse->code,
                'libelle' => $analyse->libelle,
                'unite'   => $analyse->unite,
                'milieu'  => Analyses::libelleMilieu($analyse->milieu_preleve),
            ],
            'references'  => $applicables,
            // Ce qui manque pour affiner, dit plutôt que deviné (motif des trois silences, P7-D2).
            'incertitude' => $this->incertitude($filtres),
            'referentiel' => $diffusion->estampille(SourceAnalyses::CODE),
            // LA PHRASE QUI DIT CE QUE LA RÉPONSE N'EST PAS. Sans elle, un lecteur pourrait
            // comprendre qu'une valeur hors plage est un diagnostic.
            'avertissement' => 'Le catalogue indique les valeurs habituellement observées. Il ne '
                .'qualifie pas ce résultat et ne remplace pas l\'interprétation d\'un professionnel '
                .'de santé, qui tient compte du contexte clinique et du laboratoire ayant analysé.',
        ]);
    }

    /**
     * Ce qu'on ne sait pas, et qui empêche de restreindre les strates.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<int, string>
     */
    private function incertitude(array $filtres): array
    {
        $manques = [];

        if (! isset($filtres['age_jours'])) {
            $manques[] = 'Âge inconnu : les références qui dépendent de l\'âge ne sont pas affichées.';
        }

        if (! isset($filtres['sexe'])) {
            $manques[] = 'Sexe inconnu : seules les références communes aux deux sexes sont affichées.';
        }

        return $manques;
    }
}
