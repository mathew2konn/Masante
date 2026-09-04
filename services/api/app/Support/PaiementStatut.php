<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\Interne\PaiementNotificationController;
use Tests\Unit\PaiementStatutSourceUniqueTest;

/**
 * Miroir PHP de `PaiementStatut` (`@masante/shared`, machine à états du microservice paiement,
 * CDC_06 §4.2) — B4 (ADR-056), PREMIÈRE fois que Laravel a besoin de ce vocabulaire.
 *
 * Jusqu'ici {@see PaiementNotificationController} traitait
 * `statut` comme une chaîne opaque, sans le lire : il transportait, il ne décidait rien (lot 6).
 * B4 lui fait décider — calculer une commission SEULEMENT sur un {@see self::SUCCESS} — d'où le
 * besoin d'un vocabulaire fiable plutôt que la comparaison à une chaîne en dur.
 *
 * Garde anti-divergence : {@see PaiementStatutSourceUniqueTest}.
 */
enum PaiementStatut: string
{
    case INITIATED = 'INITIATED';
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case REFUNDED = 'REFUNDED';
}
