<?php

namespace App\Notifications\Canaux;

use App\Services\Notifications\RelaisPushExpo;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Canal « push Expo » (incrément D1) — adaptateur, au sens du port `via()`.
 *
 * IL NE FAIT QU'UNE CHOSE, ET C'EST VOULU : différer. L'appel HTTP à `exp.host` part APRÈS le
 * commit de la transaction qui a produit le fait métier.
 *
 * Pourquoi ce n'est pas un détail : le canal est invoqué au milieu de la transaction qui écrit la
 * contribution. Un `exp.host` lent ou injoignable y tiendrait des verrous MySQL ouverts pendant
 * plusieurs secondes, et son échec ferait remonter une exception qui annulerait l'écriture au
 * dossier. Un service tiers n'a jamais le droit de mettre en péril l'écriture d'un dossier médical.
 *
 * `DB::afterCommit()` exécute immédiatement s'il n'y a aucune transaction en cours, et diffère
 * sinon — les deux cas d'appel sont donc couverts sans que l'appelant ait à s'en soucier.
 */
class CanalPushExpo
{
    public function __construct(private readonly RelaisPushExpo $relais)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        // À cet instant, le canal `database` a déjà écrit sa ligne et `$notification->id` porte
        // l'UUID de cette ligne : les deux canaux parlent bien de la même notification.
        DB::afterCommit(function () use ($notifiable, $notification): void {
            $this->relais->pousser($notifiable, $notification);
        });
    }
}
