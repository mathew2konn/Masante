<?php

namespace App\Support;

use App\Models\DemandeAnalyse;

/**
 * États d'une demande d'examen (B5-a, CDC_04 §109).
 *
 * `emise` à la création. `servie` est la transition que B5-b/c poseront une fois qu'un prélèvement
 * rattaché à cette demande est publié — DÉRIVÉE du circuit, jamais posée à la main : hors
 * `$fillable` de {@see DemandeAnalyse}, aucune règle de validation ne l'accepte du
 * client. `annulee` reste possible tant que rien n'a été servi.
 *
 * BACKEND-ONLY, DÉLIBÉRÉMENT (patron `StatutConsultation`, B2-a) : aucun consommateur TypeScript ne
 * lit ces valeurs en B5-a — le seul écran est le portail Blade (L12). Le jour où B5-b/c ou un écran
 * Next les consomme, la promotion dans `@masante/shared` est additive, avec sa garde
 * anti-divergence (patron `RendezVousStatutSourceUniqueTest`).
 */
enum StatutDemandeAnalyse: string
{
    case EMISE = 'emise';
    case SERVIE = 'servie';
    case ANNULEE = 'annulee';

    /** @return array<int, string> */
    public static function valeurs(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function libelle(): string
    {
        return match ($this) {
            self::EMISE => 'Émise',
            self::SERVIE => 'Servie',
            self::ANNULEE => 'Annulée',
        };
    }

    /** Une demande émise peut encore recevoir un prélèvement ou être annulée. */
    public function estOuverte(): bool
    {
        return $this === self::EMISE;
    }
}
