<?php

namespace App\Models;

use App\Console\Commands\EmettreClientApiCommand;
use App\Services\Integration\AuthentificationClientApi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un automate biologique déclaré par un laboratoire (B5-c, L10 réécrit, CDC_04 §109).
 *
 * DÉCLARÉ PAR COMMANDE (`masante:laboratoire:automate`), jamais par un écran — même raisonnement
 * qu'{@see EmettreClientApiCommand} (P11.2) : déclarer un appareil qui
 * écrira dans des dossiers patients est un acte d'exploitation vérifié hors du système.
 *
 * `client_api_id` TRACE sous quelle clé cet appareil pousse — il n'AUTHENTIFIE rien lui-même :
 * l'authentification de chaque envoi reste entièrement portée par le HMAC
 * ({@see AuthentificationClientApi}, inchangé). Le serveur vérifie
 * seulement que l'automate désigné par la charge appartient à LA MÊME structure que le client
 * authentifié — anti-usurpation d'un automate par la clé d'un autre laboratoire.
 */
class Automate extends Model
{
    protected $fillable = [
        'structure_id', 'client_api_id', 'libelle', 'marque', 'modele', 'numero_serie', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'dernier_message_le' => 'datetime',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function clientApi(): BelongsTo
    {
        return $this->belongsTo(ClientApi::class, 'client_api_id');
    }

    /** Cet automate appartient-il bien à ce laboratoire ? Anti-usurpation (M9). */
    public function appartientA(?int $structureId): bool
    {
        return $structureId !== null && $this->structure_id === $structureId;
    }
}
