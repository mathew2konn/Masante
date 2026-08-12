<?php

namespace App\Services\Notifications;

use App\Models\AppareilPush;
use App\Models\NotificationEnvoi;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Relais des notifications vers les téléphones enregistrés (incrément D1).
 *
 * DEUX RÈGLES GOUVERNENT TOUT CE FICHIER :
 *
 * 1. **Un échec de push ne remonte JAMAIS.** La notification en application est déjà écrite et
 *    consultable ; le push n'en est qu'un rappel. Faire échouer une validation de contribution
 *    parce qu'Expo est indisponible serait absurde. Tout est donc capturé, tracé, et avalé.
 *
 * 2. **Un échec laisse une trace.** Un push perdu doit rester distinguable d'un push jamais tenté —
 *    sans quoi on ne pourrait pas répondre, après un bris de glace, à « le titulaire a-t-il été
 *    prévenu ? ». D'où `notification_envois`, écrit avant l'appel et mis à jour après.
 */
class RelaisPushExpo
{
    public function __construct(private readonly ClientPushExpo $client)
    {
    }

    public function pousser(object $notifiable, Notification $notification): void
    {
        try {
            $this->relayer($notifiable, $notification);
        } catch (\Throwable $e) {
            // Filet de dernier recours : même une erreur inattendue (jeton corrompu, base
            // indisponible) ne doit pas remonter jusqu'à l'acte métier déjà commis.
            Log::warning('Relais push : échec non rattrapé', [
                'notification_id' => $notification->id ?? null,
                'erreur'          => $e->getMessage(),
            ]);
        }
    }

    private function relayer(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'getKey') || ! method_exists($notification, 'toPushExpo')) {
            return;
        }

        $appareils = AppareilPush::query()
            ->where('user_id', $notifiable->getKey())
            ->actif()
            ->get();

        if ($appareils->isEmpty()) {
            return;   // Le compte n'a aucun téléphone enregistré : rien d'anormal, rien à tracer.
        }

        $message = $notification->toPushExpo($notifiable);
        $lotMax  = (int) config('masante.notifications.push.lot_max');

        foreach ($appareils->chunk($lotMax) as $lot) {
            $this->envoyerLot($lot->values(), $message, (string) $notification->id);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AppareilPush>  $lot
     * @param  array<string, mixed>                               $message
     */
    private function envoyerLot(\Illuminate\Support\Collection $lot, array $message, string $notificationId): void
    {
        // Trace AVANT l'appel : si le processus meurt pendant la requête, on saura qu'un envoi
        // était en cours. `firstOrCreate` rend le rejeu du relais inoffensif.
        $envois = $lot->map(fn (AppareilPush $a) => NotificationEnvoi::firstOrCreate(
            ['notification_id' => $notificationId, 'appareil_id' => $a->id],
            ['statut' => NotificationEnvoi::EN_ATTENTE],
        ));

        try {
            $accuses = $this->client->envoyer($lot->pluck('jeton_expo')->all(), $message);
        } catch (\Throwable $e) {
            $this->marquerEchec($envois, $e->getMessage());

            return;
        }

        foreach ($lot as $rang => $appareil) {
            $accuse = $accuses[$rang] ?? null;
            $envoi  = $envois[$rang];

            if (is_array($accuse) && ($accuse['status'] ?? null) === 'ok') {
                $envoi->update([
                    'statut'     => NotificationEnvoi::ENVOYEE,
                    'ticket_id'  => $accuse['id'] ?? null,
                    'tentatives' => $envoi->tentatives + 1,
                    'traite_le'  => now(),
                ]);

                continue;
            }

            $motif = is_array($accuse) ? ($accuse['details']['error'] ?? $accuse['message'] ?? 'inconnu') : 'accusé manquant';

            $envoi->update([
                'statut'     => NotificationEnvoi::ECHOUEE,
                'erreur'     => mb_substr((string) $motif, 0, 255),
                'tentatives' => $envoi->tentatives + 1,
                'traite_le'  => now(),
            ]);

            // Expo demande explicitement d'arrêter d'écrire à un jeton `DeviceNotRegistered`
            // (application désinstallée, jeton réattribué). Continuer gaspillerait du quota et,
            // pire, pourrait livrer la notification au nouveau propriétaire de l'appareil.
            if ($motif === 'DeviceNotRegistered') {
                $appareil->update(['revoque_le' => now()]);
            }
        }
    }

    /** @param \Illuminate\Support\Collection<int, NotificationEnvoi> $envois */
    private function marquerEchec(\Illuminate\Support\Collection $envois, string $erreur): void
    {
        foreach ($envois as $envoi) {
            $envoi->update([
                'statut'     => NotificationEnvoi::ECHOUEE,
                'erreur'     => mb_substr($erreur, 0, 255),
                'tentatives' => $envoi->tentatives + 1,
                'traite_le'  => now(),
            ]);
        }

        Log::warning('Relais push : lot en échec', ['erreur' => $erreur]);
    }
}
