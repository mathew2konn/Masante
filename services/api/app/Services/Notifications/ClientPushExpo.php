<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;

/**
 * Appel HTTP au service de push d'Expo (incrément D1).
 *
 * ZÉRO DÉPENDANCE NOUVELLE : envoyer un push Expo est un simple `POST` JSON. Aucun SDK n'est requis
 * côté serveur, et Guzzle est déjà là (dépendance transitive de Laravel). C'est ce qui rend le
 * choix « Push Expo » gratuit côté backend — le coût est entièrement côté mobile, où il faut un
 * *development build*.
 *
 * Cette classe ne fait que parler HTTP. Elle ne décide rien : ni qui notifier, ni quoi faire d'un
 * échec. La décision est dans {@see RelaisPushExpo}.
 */
class ClientPushExpo
{
    /**
     * Envoie un message à plusieurs jetons en une requête.
     *
     * Expo renvoie un accusé (`ticket`) PAR JETON, dans l'ordre où ils ont été soumis — c'est ce
     * qui permet au relais de rattacher chaque résultat à son appareil.
     *
     * @param  array<int, string>   $jetons
     * @param  array<string, mixed> $message   titre/corps/données, sans le champ `to`
     * @return array<int, array<string, mixed>>  un accusé par jeton, dans le même ordre
     *
     * @throws \RuntimeException  la requête n'a pas abouti (réseau, 4xx/5xx, corps inattendu)
     */
    public function envoyer(array $jetons, array $message): array
    {
        $config = config('masante.notifications.push');

        $reponse = Http::timeout((float) $config['timeout_s'])
            ->acceptJson()
            ->asJson()
            ->post($config['url'], array_merge($message, ['to' => array_values($jetons)]));

        if ($reponse->failed()) {
            throw new \RuntimeException('Expo a refusé la requête : HTTP '.$reponse->status());
        }

        $accuses = $reponse->json('data');

        if (! is_array($accuses)) {
            throw new \RuntimeException('Réponse Expo inattendue : champ `data` absent.');
        }

        return $accuses;
    }
}
