<?php

namespace App\Support;

use App\Models\Protocole;
use InvalidArgumentException;

/**
 * P10b-2 — La cascade de résolution des conflits entre protocoles (CDC_08 §3 et §8).
 *
 * CLASSE PURE : aucune base, aucune horloge, aucune configuration. Tout arrive par paramètre et
 * rien n'en sort qu'un verdict. Motif `ReglesReversement` (P5.5a), `ReglesIntervalleReference`
 * (P6.7a), `ReglesCalendrierVaccinal` (P6.8b), `ReglesOrientation` (P10a).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LES CINQ CRITÈRES DU §8, ET LES DEUX QU'ON N'IMPLÉMENTE PAS
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   1. Protocole national en priorité          → `rang`, l'ordre imposé par le §3
 *   2. Protocole le plus récent                → `recence`
 *   3. Niveau de preuve le plus élevé          → `niveau_preuve`
 *   4. Avis de la spécialité concernée         → ACTE HUMAIN, non implémenté
 *   5. Validation finale par le médecin        → ACTE HUMAIN, non implémenté
 *
 * Les deux derniers ne sont pas un manque : ce sont des **actes humains devant un dilemme
 * clinique**, pas des étapes de moteur. Prétendre les rendre donnerait à une machine l'apparence
 * d'un avis médical — l'interdit du CDC_00 §4.
 *
 * ═══ L'ORDRE DES CRITÈRES 2 ET 3 EST CELUI DU CORPUS, ET IL SURPREND ═══
 *
 * Le §8 place « le plus récent » AVANT « le niveau de preuve le plus élevé ». On aurait pu juger
 * l'inverse plus sage — une recommandation de niveau A vaut mieux qu'une de niveau D publiée le
 * mois dernier. Le corpus tranche, et on le suit : le §0 du CDC_08 en fait le document qui fait
 * autorité sur les protocoles, et corriger le corpus par préférence est exactement ce que
 * CLAUDE.md interdit (« ne jamais inventer : toute ambiguïté → ADR »).
 *
 * Conséquence assumée : c'est le contrôle de publication (`ControleConflitsPublication`) qui
 * empêche qu'une version soit départagée par la seule date — là où la récence deviendrait un
 * arbitrage du calendrier plutôt qu'une décision.
 *
 * ═══ L'ORDRE EST TOTAL, ET C'EST UN DÉFAUT RÉEL DE b-1 QUI L'A IMPOSÉ ═══
 *
 * En b-1, deux validations signées dans la même seconde étaient départagées par le moteur de base
 * de données : un relecteur qui corrigeait son avis voyait le précédent faire autorité. Ici la même
 * situation se produirait entre deux publications simultanées. Le départage descend donc jusqu'au
 * numéro de version puis au code : deux protocoles distincts ne peuvent pas être ex æquo.
 */
final class ReglesResolutionConflit
{
    /** Départagé par le rang §3 (national avant régional, etc.). */
    public const CRITERE_RANG = 'rang';

    /** Départagé par le niveau de preuve (A > B > C > D). */
    public const CRITERE_PREUVE = 'niveau_preuve';

    /** Départagé par la date de publication. */
    public const CRITERE_RECENCE = 'recence';

    /**
     * Aucun critère automatisable n'a départagé.
     *
     * Structurellement inatteignable une fois l'ordre total appliqué — la valeur existe pour que
     * le cas se DISE s'il survenait, plutôt que de se deviner à l'absence de critère. Un journal
     * qui ne sait pas nommer « je n'ai pas su » laisse croire qu'il a su.
     */
    public const CRITERE_NON_DEPARTAGE = 'non_departage';

    /** §4.1 — du plus fort au plus faible. */
    private const PREUVES = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];

    /**
     * Départage deux protocoles candidats.
     *
     * @param  array{code: string, niveau_source: string, niveau_preuve: ?string, publie_le: ?string, numero: int}  $a
     * @param  array{code: string, niveau_source: string, niveau_preuve: ?string, publie_le: ?string, numero: int}  $b
     * @return array{gagnant: string, critere: string}  Le code du gagnant et le critère qui a tranché.
     */
    public static function departager(array $a, array $b): array
    {
        if ($a['code'] === $b['code']) {
            throw new InvalidArgumentException(
                'Un protocole ne se départage pas avec lui-même : deux versions actives du même '
                .'protocole ne peuvent pas coexister (contrainte du cycle de vie §6.1).'
            );
        }

        // ── Critère 1 : le rang du §3 ──────────────────────────────────────────────────
        $rangA = self::rang($a['niveau_source']);
        $rangB = self::rang($b['niveau_source']);

        if ($rangA !== $rangB) {
            return [
                'gagnant' => $rangA < $rangB ? $a['code'] : $b['code'],
                'critere' => self::CRITERE_RANG,
            ];
        }

        // ── Critère 2 : le plus récent ────────────────────────────────────────────────
        //
        // Comparaison sur l'horodatage NORMALISÉ, jamais sur le libellé de version : « 2026.2 » et
        // « 2.10 » ne se comparent pas comme des nombres, et le §6.1 n'impose aucune convention de
        // nommage. Une version sans date de publication n'a jamais été en vigueur : elle perd.
        $dateA = self::horodatage($a['publie_le']);
        $dateB = self::horodatage($b['publie_le']);

        if ($dateA !== $dateB) {
            return [
                'gagnant' => $dateA > $dateB ? $a['code'] : $b['code'],
                'critere' => self::CRITERE_RECENCE,
            ];
        }

        // ── Critère 3 : le niveau de preuve ───────────────────────────────────────────
        //
        // Un niveau absent perd contre un niveau déclaré : le contrôle qualité l'exige à la
        // publication, donc l'absence ne peut venir que d'une version publiée avant ce contrôle.
        // La traiter comme équivalente à « A » ferait gagner l'ignorance.
        $preuveA = self::preuve($a['niveau_preuve']);
        $preuveB = self::preuve($b['niveau_preuve']);

        if ($preuveA !== $preuveB) {
            return [
                'gagnant' => $preuveA < $preuveB ? $a['code'] : $b['code'],
                'critere' => self::CRITERE_PREUVE,
            ];
        }

        // ── Ordre total : le numéro de version, puis le code ──────────────────────────
        //
        // On est ici hors des critères du §8 : plus rien de MÉDICAL ne distingue les deux. Ce qui
        // suit n'est donc pas un arbitrage, c'est un DÉTERMINISME — la garantie que le même cas
        // rendra toujours le même résultat, et que le journal du §8 dira honnêtement
        // `non_departage`.
        if ($a['numero'] !== $b['numero']) {
            return [
                'gagnant' => $a['numero'] > $b['numero'] ? $a['code'] : $b['code'],
                'critere' => self::CRITERE_NON_DEPARTAGE,
            ];
        }

        return [
            'gagnant' => strcmp($a['code'], $b['code']) < 0 ? $a['code'] : $b['code'],
            'critere' => self::CRITERE_NON_DEPARTAGE,
        ];
    }

    /**
     * Le rang du §3. Une source inconnue est classée DERNIÈRE plutôt que rejetée.
     *
     * L'ENUM de la base garantit déjà les valeurs ; l'instantané d'une version publiée, lui, peut
     * porter une valeur d'un ENUM antérieur. Lever ici rendrait un protocole en vigueur
     * inapplicable après une évolution de schéma — mieux vaut le classer bas que le faire
     * disparaître sans bruit.
     */
    public static function rang(?string $source): int
    {
        $index = array_search($source, Protocole::PRIORITE_SOURCES, true);

        return $index === false ? count(Protocole::PRIORITE_SOURCES) : (int) $index;
    }

    private static function preuve(?string $niveau): int
    {
        return self::PREUVES[$niveau] ?? count(self::PREUVES);
    }

    private static function horodatage(?string $publieLe): int
    {
        if ($publieLe === null || $publieLe === '') {
            return 0;
        }

        return (int) (strtotime($publieLe) ?: 0);
    }
}
