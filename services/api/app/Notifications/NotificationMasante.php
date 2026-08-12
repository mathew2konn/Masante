<?php

namespace App\Notifications;

use App\Notifications\Canaux\CanalPushExpo;
use App\Support\TypeNotification;
use Illuminate\Notifications\Notification;

/**
 * L'unique classe de notification du carnet familial partagé (incrément D1).
 *
 * UNE classe plutôt que six : les six événements ne diffèrent que par leur type, leur phrase et
 * leur cible de navigation. Six classes n'auraient multiplié que du `via()` recopié. Le TYPE, lui,
 * est une énumération partagée avec `@masante/shared` — c'est là qu'est la source unique.
 *
 * LE CANAL, c'est `via()`. Ajouter le SMS demain, c'est ajouter une ligne ici et une classe de
 * canal ; rien d'autre ne bouge. C'est le port demandé au G1, dans l'idiome du framework.
 *
 * RÈGLE INVIOLABLE (G1) : `corps` ne contient AUCUN contenu médical. Ni diagnostic, ni traitement,
 * ni le détail d'une section du carnet. Ces phrases s'affichent sur des écrans verrouillés et
 * transitent, pour le push, par les serveurs d'Expo.
 */
class NotificationMasante extends Notification
{
    /**
     * @param  array<string, mixed>  $donnees  identifiants de navigation (membre, contribution…)
     *                                         et drapeaux d'affichage. Jamais de contenu clinique.
     */
    public function __construct(
        public readonly TypeNotification $type,
        public readonly string $corps,
        public readonly array $donnees = [],
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $canaux = ['database'];

        // Gaté OFF par défaut : le push distant est indisponible dans Expo Go depuis le SDK 53, et
        // le G4 de ce projet se tient sur Expo Go. Le relais est écrit, pas encore branché.
        if (config('masante.notifications.push.enabled')) {
            $canaux[] = CanalPushExpo::class;
        }

        return $canaux;
    }

    /**
     * Ce qui est écrit dans la colonne `type` — la valeur de l'énumération, pas le nom de classe.
     *
     * Sans cela, la base stockerait `App\Notifications\NotificationMasante` pour les six types, et
     * le mobile n'aurait rien pour choisir son écran. Un renommage de classe casserait en plus les
     * lignes déjà écrites.
     */
    public function databaseType(object $notifiable): string
    {
        return $this->type->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'titre' => $this->type->titre(),
            'corps' => $this->corps,
        ], $this->donnees);
    }

    /**
     * La charge utile poussée au téléphone.
     *
     * `data` ne porte que de quoi ouvrir le bon écran à l'appui : le contenu, lui, sera rechargé
     * depuis l'API une fois l'utilisateur authentifié.
     *
     * @return array<string, mixed>
     */
    public function toPushExpo(object $notifiable): array
    {
        return [
            'title' => $this->type->titre(),
            'body'  => $this->corps,
            'data'  => [
                'notification_id' => $this->id,
                'type'            => $this->type->value,
                'membre_id'       => $this->donnees['membre_id'] ?? null,
            ],
            // `high` pour qu'Android réveille l'appareil : une notification de bris de glace qui
            // arrive au prochain déverrouillage n'a plus d'objet.
            'priority' => ($this->donnees['urgent'] ?? false) ? 'high' : 'default',
            'sound'    => 'default',
        ];
    }
}
