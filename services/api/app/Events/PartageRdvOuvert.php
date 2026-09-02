<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * B1-c — le médecin de ce rendez-vous vient d'ouvrir son accès de 30 minutes (D9, CDC_11 §9).
 *
 * ═══ PREMIER ÉVÉNEMENT DIFFUSÉ DU PROJET (Reverb, jamais installé avant B1-c) ═══
 *
 * `ShouldBroadcastNow`, pas `ShouldBroadcast` : ce projet tourne `QUEUE_CONNECTION=database` sans
 * worker actif en développement (vérifié au G0 — aucun `php artisan queue:work` dans le flux réel,
 * seul le script `dev` par défaut du squelette Laravel, jamais exécuté ici). Un événement mis en
 * file mais jamais traité ne serait jamais diffusé — l'exact contraire de « temps réel ». La
 * diffusion synchrone n'ajoute qu'un appel HTTP vers le serveur Reverb, déjà local.
 *
 * RÈGLE INVIOLABLE (posée en P7-D1 pour les notifications push, ici transposée à un canal ENCORE
 * PLUS EXPOSÉ — une WebSocket ouverte, pas un push isolé) : AUCUN CONTENU MÉDICAL. Le nom du
 * médecin n'en est pas un — le patient le connaît déjà, c'est celui de SON rendez-vous.
 */
class PartageRdvOuvert implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $rdvId,
        public readonly string $medecinNom,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("rdv.{$this->rdvId}.presence")];
    }

    public function broadcastAs(): string
    {
        return 'partage.ouvert';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'medecin' => $this->medecinNom,
            'a' => now()->toIso8601String(),
        ];
    }
}
