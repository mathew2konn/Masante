<?php

namespace App\Services\Analyse;

use App\Support\Analyses;

/**
 * Quelles strates de référence s'appliquent à un patient (CDC_09 §7.3).
 *
 * ═══ CLASSE PURE ═══
 *
 * Aucune base, aucune horloge, aucun modèle Eloquent : tout l'état entre par les paramètres. Motif
 * de `ReglesReversement` (P5.5a), `ReglesRapprochement` (P5.5c) et `ReglesVerificationSignature`
 * (P6.5b) — le service rassemble, les règles jugent.
 *
 * ═══ CE QU'ELLE FAIT, ET LA LIGNE QU'ELLE NE FRANCHIT PAS ═══
 *
 * Elle SÉLECTIONNE les strates dont les critères démographiques correspondent. Elle **ne compare
 * jamais un résultat à une plage** et ne renvoie aucun statut : c'est la décision du propriétaire —
 * la plateforme affiche la valeur et la référence côte à côte, elle ne conclut pas.
 *
 * La raison est dans le corpus : §7.3 ne décrit **aucune** stratification. Conclure sur une
 * référence unique dirait à une femme enceinte que son hémoglobine est basse alors qu'elle est
 * normale pour elle — une affirmation fausse portée par une machine, dans un carnet de santé.
 *
 * ═══ LES STRATES CONDITIONNELLES SONT AJOUTÉES, JAMAIS CHOISIES ═══
 *
 * Le carnet connaît la grossesse (`suivi_grossesse`), donc la tentation de choisir pour la patiente
 * est réelle. On s'en abstient : les strates de grossesse sont renvoyées **en plus** de la strate
 * démographique, marquées comme conditionnelles, et le lecteur voit celle qui le concerne. C'est le
 * motif des **trois silences** de P7-D2 — une information annoncée pour ce qu'elle est vaut mieux
 * qu'un choix fait à la place de quelqu'un.
 */
final class ReglesIntervalleReference
{
    /**
     * Les strates applicables, dans l'ordre où un humain veut les lire.
     *
     * @param  array<int, array<string, mixed>>  $strates  chaque strate : sexe, age_min_jours,
     *                                                     age_max_jours, etat_physiologique, …
     * @param  string|null  $sexe      'M' | 'F' | null si inconnu
     * @param  int|null     $ageJours  null si la date de naissance est inconnue
     * @return array<int, array<string, mixed>> les strates retenues, chacune enrichie de
     *                                          `conditionnelle` et `retenue_pour`
     */
    public function applicables(array $strates, ?string $sexe, ?int $ageJours): array
    {
        $retenues = [];

        foreach ($strates as $strate) {
            $conditionnelle = Analyses::estConditionnel($strate['etat_physiologique'] ?? 'standard');

            if (! $this->correspondAuSexe($strate, $sexe)) {
                continue;
            }

            if (! $this->correspondALAge($strate, $ageJours)) {
                continue;
            }

            $retenues[] = $strate + [
                'conditionnelle' => $conditionnelle,
                // Pourquoi cette strate est là : ce qui distingue « votre référence » d'une
                // référence affichée pour information.
                'retenue_pour'   => $conditionnelle
                    ? 'S\'applique seulement dans cet état physiologique.'
                    : 'Correspond à l\'âge et au sexe du patient.',
            ];
        }

        // Le standard d'abord, les strates conditionnelles ensuite : on lit sa référence, puis les
        // cas particuliers qui peuvent la remplacer.
        usort($retenues, static fn (array $a, array $b): int => ($a['conditionnelle'] ? 1 : 0) <=> ($b['conditionnelle'] ? 1 : 0));

        return $retenues;
    }

    /**
     * Le sexe correspond-il ?
     *
     * SEXE INCONNU : on garde les strates `tous` uniquement. Renvoyer celles de l'homme ET de la
     * femme laisserait le lecteur choisir la plus flatteuse ; n'en renvoyer aucune serait un
     * silence. Les strates communes restent vraies pour tout le monde, et l'appelant sait dire que
     * le sexe manque.
     *
     * @param  array<string, mixed>  $strate
     */
    private function correspondAuSexe(array $strate, ?string $sexe): bool
    {
        $strateSexe = $strate['sexe'] ?? 'tous';

        if ($strateSexe === 'tous') {
            return true;
        }

        return $sexe !== null && strtoupper($sexe) === $strateSexe;
    }

    /**
     * L'âge correspond-il ?
     *
     * ÂGE INCONNU : on ne garde que les strates SANS borne d'âge. Une strate « 0 à 28 jours » ne
     * peut pas être proposée à quelqu'un dont on ignore l'âge — ce serait présenter la référence
     * d'un nouveau-né à un adulte possible.
     *
     * Bornes INCLUSIVES des deux côtés, et c'est un choix : une strate « 0–28 jours » suivie d'une
     * « 29–365 » ne laisse aucun trou. Le contrôle qualité signale les chevauchements.
     *
     * @param  array<string, mixed>  $strate
     */
    private function correspondALAge(array $strate, ?int $ageJours): bool
    {
        $min = $strate['age_min_jours'] ?? null;
        $max = $strate['age_max_jours'] ?? null;

        if ($min === null && $max === null) {
            return true;
        }

        if ($ageJours === null) {
            return false;
        }

        if ($min !== null && $ageJours < (int) $min) {
            return false;
        }

        return ! ($max !== null && $ageJours > (int) $max);
    }

    /**
     * Deux strates se chevauchent-elles ? (contrôle qualité §10)
     *
     * Deux strates qui couvrent le même sexe, le même âge ET le même état physiologique sont une
     * contradiction : le référentiel affirmerait deux plages pour la même personne, et l'écran en
     * afficherait deux sans pouvoir dire laquelle vaut.
     *
     * Deux strates de MÊME démographie mais d'états DIFFÉRENTS ne se chevauchent pas : c'est le
     * fonctionnement normal (une femme adulte a une référence standard et une de grossesse).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function seChevauchent(array $a, array $b): bool
    {
        if (($a['etat_physiologique'] ?? 'standard') !== ($b['etat_physiologique'] ?? 'standard')) {
            return false;
        }

        $sexeA = $a['sexe'] ?? 'tous';
        $sexeB = $b['sexe'] ?? 'tous';

        if ($sexeA !== 'tous' && $sexeB !== 'tous' && $sexeA !== $sexeB) {
            return false;
        }

        return $this->intervallesSeCoupent(
            $a['age_min_jours'] ?? null,
            $a['age_max_jours'] ?? null,
            $b['age_min_jours'] ?? null,
            $b['age_max_jours'] ?? null,
        );
    }

    private function intervallesSeCoupent(?int $minA, ?int $maxA, ?int $minB, ?int $maxB): bool
    {
        $debutA = $minA ?? 0;
        $finA   = $maxA ?? PHP_INT_MAX;
        $debutB = $minB ?? 0;
        $finB   = $maxB ?? PHP_INT_MAX;

        return $debutA <= $finB && $debutB <= $finA;
    }
}
