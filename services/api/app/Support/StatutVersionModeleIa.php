<?php

namespace App\Support;

use App\Services\Triage\ServiceGouvernanceModeleIa;

/**
 * P10c-3-i (F17/F18) — Le cycle de gouvernance d'une version de modèle IA (CDC_05 §7.2/§8).
 *
 * Vocabulaire ADOPTÉ, pas inventé : `CDC_05 §8` nomme littéralement « statut (candidat / validé /
 * actif / archivé) » — motif P6.8a, on ne réinvente pas un nom que le corpus a déjà choisi.
 *
 * ═══ QUATRE ÉTATS, DEUX FRANCHISSABLES PAR CET INCRÉMENT ═══
 *
 * `candidat` naît AUTOMATIQUEMENT à la fin d'un entraînement réussi (un fait mécanique, aucun
 * jugement humain requis). `valide` exige le quatre-yeux du §9 (« validation clinique... avant
 * toute mise en production d'un modèle influençant une décision de soins ») — voir
 * {@see ServiceGouvernanceModeleIa}.
 *
 * `actif` et `archive` n'ont AUCUN sens dans cet incrément : rien n'est branché sur le flux vivant
 * (F18/Y10 du plan G1 P10c-3-i) — « actif » ne prendra un sens que le jour où P10c-3-ii décidera
 * qu'un modèle sert réellement des triages. Les valeurs existent déjà dans l'ENUM (même motif que
 * `predictions_ia.mode` portant `hybride` avant qu'il soit atteignable, P10c-2-i F10) pour qu'aucune
 * migration de donnée de production ne soit nécessaire le jour où elles le deviennent.
 */
final class StatutVersionModeleIa
{
    public const CANDIDAT = 'candidat';

    public const VALIDE = 'valide';

    /** Inatteignable dans cet incrément (P10c-3-ii). Voir l'en-tête. */
    public const ACTIF = 'actif';

    /** Inatteignable dans cet incrément (P10c-3-ii). Voir l'en-tête. */
    public const ARCHIVE = 'archive';

    /** @var array<int, string> */
    public const VALEURS = [self::CANDIDAT, self::VALIDE, self::ACTIF, self::ARCHIVE];

    public static function existe(string $statut): bool
    {
        return in_array($statut, self::VALEURS, true);
    }
}
