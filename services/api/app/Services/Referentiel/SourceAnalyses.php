<?php

namespace App\Services\Referentiel;

use App\Models\Analyse;
use App\Models\AnalyseReference;
use App\Services\Analyse\GenerateurCodeAnalyse;
use App\Services\Analyse\ReglesIntervalleReference;
use App\Support\Analyses;

/**
 * Catalogue national des analyses (CDC_09 §7.3), sous gouvernance §10.
 *
 * ═══ UN SEUL INSTANTANÉ POUR LE CATALOGUE ET SES RÉFÉRENCES ═══
 *
 * Même raison qu'en P6.6a pour les interactions : publier séparément laisserait une strate désigner
 * une analyse absente de la version en vigueur — la référence serait **irrésoluble**, et le
 * référentiel affirmerait quelque chose d'invérifiable. Une seule version citable par une décision.
 *
 * Les strates sont portées par le **code national** de leur analyse, jamais par un identifiant
 * technique : un instantané doit rester lisible et rejouable sans la base qui l'a produit
 * (précédent P6.4c, où l'empreinte porte le contenu et non le chemin).
 *
 * ═══ LA PROJECTION PREND LA LIGNE ENTIÈRE ═══
 *
 * La question a été reposée, comme en P6.6a, et non recopiée : rien n'écrit automatiquement dans
 * `analyses` ni dans `analyse_references`. Ce sont des données d'autorité de bout en bout — un
 * délai de rendu et une plage de référence engagent celui qui les publie.
 */
final class SourceAnalyses implements SourceReferentiel
{
    public const CODE = 'analyses';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Catalogue des analyses et valeurs de référence';
    }

    /**
     * §10 « chaque référentiel a un responsable désigné ». Le ministère — un laboratoire ne peut pas
     * fixer les valeurs de référence nationales : il serait juge et partie sur les résultats qu'il
     * rend lui-même.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        $analyses = Analyse::query()
            // Ordre stable : `code` est UNIQUE par pays mais reste NULL avant le backfill, d'où
            // `id` en second — l'ordre doit être total dans tous les cas (précédent P6.4a).
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        $lignes = $analyses->map(fn (Analyse $a): array => [
            'code'                   => $a->code,
            'pays_code'              => $a->pays_code,
            'loinc'                  => $a->loinc,
            'libelle'                => $a->libelle,
            'description'            => $a->description,
            'categorie'              => $a->categorie,
            'milieu_preleve'         => $a->milieu_preleve,
            'unite'                  => $a->unite,
            'methode'                => $a->methode,
            'conditions_prelevement' => $a->conditions_prelevement,
            'conservation'           => $a->conservation,
            'delai_rendu_heures'     => $a->delai_rendu_heures,
            'actif'                  => (bool) $a->actif,
        ])->all();

        return [...$lignes, ...$this->references($analyses->pluck('code', 'id')->all())];
    }

    /**
     * Les strates, portées par le code national de leur analyse.
     *
     * @param  array<int, string|null>  $codesParId
     * @return array<int, array<string, mixed>>
     */
    private function references(array $codesParId): array
    {
        return AnalyseReference::query()
            ->orderBy('analyse_id')
            ->orderBy('sexe')
            ->orderBy('age_min_jours')
            ->orderBy('id')
            ->get()
            ->map(fn (AnalyseReference $r): array => [
                'type'               => 'reference',
                'analyse'            => $codesParId[$r->analyse_id] ?? null,
                'sexe'               => $r->sexe,
                'age_min_jours'      => $r->age_min_jours,
                'age_max_jours'      => $r->age_max_jours,
                'etat_physiologique' => $r->etat_physiologique,
                'valeur_min'         => $r->valeur_min,
                'valeur_max'         => $r->valeur_max,
                'critique_bas'       => $r->critique_bas,
                'critique_haut'      => $r->critique_haut,
                'libelle_strate'     => $r->libelle_strate,
                'source'             => $r->source,
                'source_detail'      => $r->source_detail,
            ])
            ->all();
    }

    /**
     * Contrôles qualité §10 — bloquants à la publication.
     *
     * Ce qu'ils garantissent tient en une phrase : **le catalogue ne doit pas pouvoir affirmer
     * quelque chose d'invérifiable, de contradictoire ou d'anonyme.**
     */
    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];
        [$analyses, $references] = $this->separer($contenu);

        if ($analyses === []) {
            return ['Le catalogue est vide : aucune analyse à publier.'];
        }

        $codesVus = [];
        $unites = [];

        foreach ($analyses as $ligne) {
            $code = $ligne['code'];
            $nom  = $code ?? ($ligne['libelle'] ?: '(sans code ni libellé)');

            if ($code === null) {
                $erreurs[] = "« {$nom} » : aucun code national attribué — lancez `masante:analyses:backfill`.";
            } elseif (! GenerateurCodeAnalyse::formeValide($code)) {
                $erreurs[] = "« {$nom} » : code national mal formé (attendu ANA + 6 chiffres).";
            } else {
                // LE PAYS FAIT PARTIE DE LA CLÉ — sinon le contrôle serait plus strict que l'index
                // et le catalogue deviendrait impubliable dès le second pays (défaut du G2 de P6.5a).
                $cle = $ligne['pays_code'].'-'.$code;

                if (isset($codesVus[$cle])) {
                    $erreurs[] = "Doublon : le code « {$code} » apparaît plusieurs fois pour {$ligne['pays_code']}.";
                }
                $codesVus[$cle] = true;
                $unites[$code] = $ligne['unite'];
            }

            if (trim((string) $ligne['libelle']) === '') {
                $erreurs[] = "« {$nom} » : libellé absent.";
            }

            // Une analyse sans unité rend son résultat ininterprétable — c'est précisément
            // l'incohérence que §7.3 veut supprimer.
            if (trim((string) $ligne['unite']) === '') {
                $erreurs[] = "« {$nom} » : unité de mesure absente (§7.3).";
            }

            if ($ligne['categorie'] !== null && ! in_array($ligne['categorie'], Analyses::categories(), true)) {
                $erreurs[] = "« {$nom} » : catégorie inconnue ({$ligne['categorie']}).";
            }

            if ($ligne['milieu_preleve'] !== null && ! in_array($ligne['milieu_preleve'], Analyses::milieux(), true)) {
                $erreurs[] = "« {$nom} » : milieu prélevé inconnu ({$ligne['milieu_preleve']}).";
            }
        }

        $erreurs = [...$erreurs, ...$this->controlerLesStrates($references, $codesVus)];

        return $erreurs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @param  array<string, bool>  $codesVus
     * @return array<int, string>
     */
    private function controlerLesStrates(array $references, array $codesVus): array
    {
        $erreurs = [];
        $regles = new ReglesIntervalleReference();
        $parAnalyse = [];

        foreach ($references as $strate) {
            $analyse = $strate['analyse'];
            $nom = ($analyse ?? '?').' / '.$strate['libelle_strate'];

            // Une strate qui désigne une analyse absente du contenu est irrésoluble : la publier
            // ferait affirmer au catalogue une plage que personne ne peut rattacher.
            if ($analyse === null) {
                $erreurs[] = "Strate « {$strate['libelle_strate']} » : elle désigne une analyse sans code national.";

                continue;
            }

            // LA GARDE CENTRALE DU MODULE. Un intervalle sans provenance est une rumeur — et un
            // référentiel national qui affirme une plage doit pouvoir dire d'où elle vient. Même
            // règle qu'en P6.6a pour les interactions.
            if (! in_array($strate['source'], Analyses::sourcesReference(), true)) {
                $erreurs[] = "Strate {$nom} : source absente ou inconnue — le catalogue ne peut pas "
                    .'affirmer une valeur de référence sans dire d\'où elle vient.';
            }

            if ($strate['valeur_min'] === null && $strate['valeur_max'] === null) {
                $erreurs[] = "Strate {$nom} : aucune borne — la strate n'affirme rien.";
            }

            if ($strate['valeur_min'] !== null && $strate['valeur_max'] !== null
                && $strate['valeur_min'] > $strate['valeur_max']) {
                $erreurs[] = "Strate {$nom} : borne basse supérieure à la borne haute.";
            }

            if ($strate['age_min_jours'] !== null && $strate['age_max_jours'] !== null
                && $strate['age_min_jours'] > $strate['age_max_jours']) {
                $erreurs[] = "Strate {$nom} : âge minimum supérieur à l'âge maximum.";
            }

            if (trim((string) $strate['libelle_strate']) === '') {
                $erreurs[] = "Strate de « {$analyse} » : libellé absent — le lecteur ne saurait pas "
                    .'à qui cette plage s\'applique.';
            }

            $parAnalyse[$analyse][] = $strate;
        }

        // Chevauchements : deux plages pour la même personne, c'est une contradiction que l'écran
        // afficherait sans pouvoir dire laquelle vaut.
        foreach ($parAnalyse as $analyse => $strates) {
            $nb = count($strates);

            for ($i = 0; $i < $nb; $i++) {
                for ($j = $i + 1; $j < $nb; $j++) {
                    if ($regles->seChevauchent($strates[$i], $strates[$j])) {
                        $erreurs[] = "« {$analyse} » : les strates « {$strates[$i]['libelle_strate']} » et "
                            ."« {$strates[$j]['libelle_strate']} » se chevauchent — deux plages pour la même personne.";
                    }
                }
            }
        }

        return $erreurs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contenu
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function separer(array $contenu): array
    {
        $analyses = [];
        $references = [];

        foreach ($contenu as $ligne) {
            if (($ligne['type'] ?? null) === 'reference') {
                $references[] = $ligne;
            } else {
                $analyses[] = $ligne;
            }
        }

        return [$analyses, $references];
    }
}
