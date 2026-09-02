<?php

namespace App\Support;

use App\Events\PartageRdvOuvert;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B1-c — diffuse un événement de présence SANS JAMAIS mettre en péril l'appelant (D9).
 *
 * MÊME PRINCIPE QUE P7-D1 SUR LE PUSH, transposé à un tiers synchrone : « un tiers n'a jamais le
 * droit de mettre en péril l'écriture d'un dossier médical ». Le push partait après le commit pour
 * ne jamais tenir de verrou MySQL ; ici {@see ShouldBroadcastNow}
 * appelle le serveur Reverb DANS la requête (aucun worker de file n'est actif en développement —
 * voir {@see PartageRdvOuvert}) : si ce serveur est injoignable ou mal configuré,
 * l'exception NE DOIT JAMAIS remonter jusqu'à l'ouverture de l'accès, jusqu'à sa clôture, ni
 * surtout jusqu'à l'écriture d'une ordonnance. La présence en direct est un confort pour le
 * patient qui regarde son téléphone ; ce n'est jamais une condition de l'acte de soin.
 */
class DiffusionPresence
{
    public static function diffuser(ShouldBroadcastNow $evenement): void
    {
        try {
            broadcast($evenement);
        } catch (Throwable $e) {
            Log::warning('Diffusion de présence échouée (accès/écriture non affectés)', [
                'evenement' => $evenement::class,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
