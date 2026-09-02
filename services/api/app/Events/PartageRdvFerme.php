<?php

namespace App\Events;

use App\Services\SessionDossierService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * B1-c — la session d'accès partagé s'est fermée (clôture explicite ou expiration à 30 min).
 * Voir {@see PartageRdvOuvert} pour le choix de `ShouldBroadcastNow` et la règle « zéro contenu
 * médical ». `$motif` n'est PAS diffusé (« manuelle » vs « expiration » n'intéresse que le
 * journal d'audit, {@see SessionDossierService::fermer()}) : le patient a seulement
 * besoin de savoir que l'accès est refermé.
 */
class PartageRdvFerme implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $rdvId) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("rdv.{$this->rdvId}.presence")];
    }

    public function broadcastAs(): string
    {
        return 'partage.ferme';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['a' => now()->toIso8601String()];
    }
}
