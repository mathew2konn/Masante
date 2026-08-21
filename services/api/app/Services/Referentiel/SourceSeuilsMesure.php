<?php

namespace App\Services\Referentiel;

use App\Models\ReferentielMesure;

/**
 * Référentiel national des seuils de mesure (table `referentiels_mesure`, Module 5 / 5.6).
 *
 * POURQUOI CELUI-CI EN PREMIER : il porte de VRAIES règles cliniques. Le commentaire de sa
 * migration annonce lui-même qu'« un médecin peut les corriger par un UPDATE, sans redéployer ».
 * C'est précisément cet `UPDATE` que le §10 demande de versionner et d'auditer : sans version,
 * corriger un seuil rend inexplicable toute mesure jugée « critique » avant la correction.
 *
 * DEPUIS L1 (ADR-025 §5), LE SOCLE NE FAIT PLUS QU'OBSERVER : `MesureSanteService` lit la version
 * publiée de ce référentiel au lieu de la table. La table reste inchangée et reste le contenu de
 * travail — c'est elle qu'une proposition fige — mais elle ne gouverne plus la qualification d'une
 * mesure tant qu'elle n'a pas été publiée.
 *
 * L'instantané ne porte NI `id` NI horodatages, contrairement à celui des symptômes de triage qui
 * inclut son `id` délibérément (un triage archivé y désigne les symptômes retenus). Ici
 * `type_mesure` est UNIQUE : c'est la clé naturelle, et l'identifiant technique n'a rien à porter.
 */
final class SourceSeuilsMesure implements SourceReferentiel
{
    public const CODE = 'seuils_mesure';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Seuils nationaux des mesures de santé';
    }

    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return ReferentielMesure::query()
            // Ordre stable : `type_mesure` est UNIQUE en base, donc l'ordre est total et ne
            // dépend ni des insertions ni du plan d'exécution.
            ->orderBy('type_mesure')
            ->get()
            ->map(fn (ReferentielMesure $m): array => [
                'type_mesure'     => $m->type_mesure,
                'libelle'         => $m->libelle,
                'unite'           => $m->unite,
                'valeur_min'      => (float) $m->valeur_min,
                'valeur_max'      => (float) $m->valeur_max,
                'normal_min'      => (float) $m->normal_min,
                'normal_max'      => (float) $m->normal_max,
                'critique_bas'    => $m->critique_bas === null ? null : (float) $m->critique_bas,
                'critique_haut'   => $m->critique_haut === null ? null : (float) $m->critique_haut,
                'decimales'       => (int) $m->decimales,

                // ═══ P10c-1 — LA FRAÎCHEUR ENTRE DANS LA PROJECTION, ET L'EMPREINTE CHANGE ═══
                //
                // Conséquence assumée et dite avant de coder : ajouter ce champ modifie l'empreinte
                // du référentiel, donc la prochaine proposition divergera de la version en vigueur.
                // Ce n'est pas une dérive — c'est le même cas que `forme_juridique` en P6.4d et le
                // rattachement des spécialités en P6.8a.
                //
                // Pourquoi elle Y ENTRE : elle décide si une mesure du carnet est **proposée** au
                // patient au moment d'un triage. C'est une décision de santé publique — combien de
                // temps une constante reste-t-elle représentative ? — pas un réglage d'affichage.
                // La laisser hors de la projection permettrait de la corriger par un `UPDATE`, sans
                // relecture, alors qu'elle gouverne ce qu'un citoyen voit pré-rempli.
                'fraicheur_max_minutes' => $m->fraicheur_max_minutes === null
                    ? null
                    : (int) $m->fraicheur_max_minutes,

                'ordre'           => (int) $m->ordre,
                'conseil_anormal' => $m->conseil_anormal,
            ])
            ->all();
    }

    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : aucun seuil de mesure à publier.'];
        }

        $typesVus = [];

        foreach ($contenu as $ligne) {
            $type = $ligne['type_mesure'];

            // Unicité / doublons (§10).
            if (isset($typesVus[$type])) {
                $erreurs[] = "Doublon : le type de mesure « {$type} » apparaît plusieurs fois.";
            }
            $typesVus[$type] = true;

            // Format.
            if (trim((string) $ligne['unite']) === '') {
                $erreurs[] = "« {$type} » : unité de mesure absente.";
            }
            if (trim((string) $ligne['conseil_anormal']) === '') {
                $erreurs[] = "« {$type} » : conseil au patient absent — une valeur hors norme serait "
                    .'affichée sans aucune indication.';
            }
            if ($ligne['decimales'] < 0 || $ligne['decimales'] > 3) {
                $erreurs[] = "« {$type} » : nombre de décimales aberrant ({$ligne['decimales']}).";
            }

            // ═══ P10c-1 — UNE FENÊTRE DE FRAÎCHEUR NULLE OU NÉGATIVE DIRAIT DEUX CHOSES ═══
            //
            // `null` a un sens net et sûr : cette mesure n'est **jamais** proposée au triage. Un
            // `0` dirait la même chose par un autre chemin — donc deux façons d'écrire le même
            // fait, dont l'une a l'air d'un réglage et l'autre d'un oubli. On n'en garde qu'une.
            //
            // Le contrôle N'EXIGE PAS qu'une fenêtre soit déclarée : *un contrôle qu'on ne peut pas
            // satisfaire n'est pas une exigence, c'est un mur* (P6.8c sur les codes CIM). Une
            // absence est légitime et se lit comme un refus de proposer.
            if (array_key_exists('fraicheur_max_minutes', $ligne)
                && $ligne['fraicheur_max_minutes'] !== null
                && (int) $ligne['fraicheur_max_minutes'] < 1) {
                $erreurs[] = "« {$type} » : fenêtre de fraîcheur nulle ou négative "
                    ."({$ligne['fraicheur_max_minutes']}). Laissez-la vide pour ne jamais proposer "
                    .'cette mesure au triage — un 0 dirait la même chose sans qu\'on sache si c\'est '
                    .'un réglage ou un oubli.';
            }

            // Cohérence des bornes. Deux couples à ne pas confondre : la PLAUSIBILITÉ de saisie
            // (valeur_min/max) doit englober la NORMALITÉ clinique (normal_min/max), sinon une
            // valeur parfaitement normale serait refusée à la saisie comme faute de frappe.
            if ($ligne['valeur_min'] >= $ligne['valeur_max']) {
                $erreurs[] = "« {$type} » : bornes de plausibilité incohérentes "
                    ."({$ligne['valeur_min']} ≥ {$ligne['valeur_max']}).";
            }
            if ($ligne['normal_min'] > $ligne['normal_max']) {
                $erreurs[] = "« {$type} » : bornes de normalité incohérentes "
                    ."({$ligne['normal_min']} > {$ligne['normal_max']}).";
            }
            if ($ligne['normal_min'] < $ligne['valeur_min'] || $ligne['normal_max'] > $ligne['valeur_max']) {
                $erreurs[] = "« {$type} » : la plage normale sort de la plage plausible — une valeur "
                    .'normale serait rejetée à la saisie.';
            }

            // Seuils critiques : un critique bas au-dessus du normal bas n'a pas de sens.
            if ($ligne['critique_bas'] !== null && $ligne['critique_bas'] > $ligne['normal_min']) {
                $erreurs[] = "« {$type} » : seuil critique bas ({$ligne['critique_bas']}) au-dessus "
                    ."du minimum normal ({$ligne['normal_min']}).";
            }
            if ($ligne['critique_haut'] !== null && $ligne['critique_haut'] < $ligne['normal_max']) {
                $erreurs[] = "« {$type} » : seuil critique haut ({$ligne['critique_haut']}) en dessous "
                    ."du maximum normal ({$ligne['normal_max']}).";
            }
        }

        return $erreurs;
    }
}
