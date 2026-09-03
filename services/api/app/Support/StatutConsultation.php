<?php

namespace App\Support;

/**
 * États d'une consultation (B2-a, CDC_11 §5.2).
 *
 * DEUX ÉTATS, ET PAS D'« ANNULÉE ». Une consultation ouverte a eu lieu : le soignant a ouvert le
 * dossier d'un patient présent devant lui. Offrir un bouton « annuler » laisserait effacer un acte
 * de soin, alors que tout ce projet est bâti sur l'inverse — une entrée du dossier se rétracte
 * (SoftDeletes de `notes_observations`), elle ne se nie pas.
 *
 * BACKEND-ONLY, DÉLIBÉRÉMENT. Ces valeurs ne sont pas promues dans `@masante/shared` parce
 * qu'AUCUN consommateur TypeScript ne les lit : l'écran de B2-a est au portail Blade. Les y
 * inscrire d'avance ferait une clé morte — exactement ce que `RendezVousStatut` a été depuis P0
 * jusqu'à ce que B1-a le découvre (zéro import dans tout le monorepo). Le jour où un écran Next
 * les consomme, la promotion est additive, et une garde anti-divergence l'accompagnera (patron
 * `RendezVousStatutSourceUniqueTest`).
 */
enum StatutConsultation: string
{
    case EN_COURS = 'en_cours';
    case CLOTUREE = 'cloturee';

    /** @return array<int, string> */
    public static function valeurs(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function libelle(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::CLOTUREE => 'Clôturée',
        };
    }

    /** Une consultation clôturée est terminale : plus aucune écriture ne s'y rattache. */
    public function estTerminal(): bool
    {
        return $this === self::CLOTUREE;
    }
}
