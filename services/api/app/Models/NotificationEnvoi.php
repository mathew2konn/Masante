<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un envoi poussé, pour un appareil donné (incrément D1).
 *
 * Elle existe pour répondre à une seule question, mais une question qui compte : « le père a-t-il
 * été prévenu, oui ou non ? ». Sans elle, un push perdu serait indistinguable d'un push jamais
 * tenté — ce qui est inacceptable après un bris de glace.
 */
class NotificationEnvoi extends Model
{
    protected $table = 'notification_envois';

    public const EN_ATTENTE = 'EN_ATTENTE';
    public const ENVOYEE    = 'ENVOYEE';
    public const ECHOUEE    = 'ECHOUEE';

    protected $fillable = [
        'notification_id',
        'appareil_id',
        'statut',
        'ticket_id',
        'erreur',
        'tentatives',
        'traite_le',
    ];

    protected function casts(): array
    {
        return [
            'tentatives' => 'integer',
            'traite_le'  => 'datetime',
        ];
    }

    public function appareil(): BelongsTo
    {
        return $this->belongsTo(AppareilPush::class, 'appareil_id');
    }
}
