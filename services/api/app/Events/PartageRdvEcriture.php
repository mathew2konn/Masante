<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * B1-c — le médecin vient d'ajouter un élément au carnet, pendant une session `rdv_partage` (D9).
 *
 * ═══ CE QUI N'EST DÉLIBÉRÉMENT PAS DANS LE PAYLOAD, ET POURQUOI ═══
 *
 * Ni la SECTION (« ordonnances », « antécédents »…) ni l'identifiant de l'entrée écrite. La règle
 * du projet (P7-D1) est « aucun contenu médical » ; le nom d'une section n'en est pas un au sens
 * strict — mais ce canal est plus exposé qu'un push (une WebSocket ouverte pendant toute la
 * consultation, potentiellement visible à l'écran verrouillé si le patient a la fiche ouverte en
 * salle d'attente). Rester EN DEÇÀ de la règle plutôt qu'à sa limite exacte : le patient voit « le
 * médecin vient d'ajouter un élément », jamais lequel. Voir {@see PartageRdvOuvert} pour le choix
 * de `ShouldBroadcastNow`.
 */
class PartageRdvEcriture implements ShouldBroadcastNow
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
        return 'partage.ecriture';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['a' => now()->toIso8601String()];
    }
}
