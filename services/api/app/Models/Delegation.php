<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Délégation d'accès (voie 3, Note_Continuite chap. 4 ; élargie par le carnet familial partagé).
 *
 * Active = acceptée par le délégué ET non révoquée. Un droit non accepté ne vaut rien : c'est le
 * consentement du délégué qui ouvre l'accès, jamais la seule intention du titulaire.
 *
 * HIÉRARCHIE DES DROITS (stricte, du plus faible au plus fort) :
 *   qr_generation  — présenter le QR du membre, sans rien voir du dossier (périmètre historique)
 *   lecture        — + lire le dossier (incrément A)
 *   lecture_ecriture — + contribuer au dossier, au brouillon (incrément C, pas encore attribué)
 *
 * Chaque niveau contient le précédent : qui peut lire peut évidemment présenter le QR.
 */
class Delegation extends Model
{
    public const DROIT_QR = 'qr_generation';

    public const DROIT_LECTURE = 'lecture';

    public const DROIT_LECTURE_ECRITURE = 'lecture_ecriture';

    /** Droits qui emportent la lecture du dossier. */
    public const DROITS_LECTURE = [self::DROIT_LECTURE, self::DROIT_LECTURE_ECRITURE];

    protected $fillable = [
        'titulaire_user_id',
        'delegue_user_id',
        'membre_id',
        'droits',
        // Incrément B — assertion du responsable : « ce carnet est celui de la personne que
        // j'invite ». C'est elle qui autorise la revendication, jamais un score de similarité.
        'est_le_dossier_du_delegue',
        'invitee_at',
        'acceptee_at',
        'revoquee_at',
    ];

    protected function casts(): array
    {
        return [
            'invitee_at' => 'datetime',
            'acceptee_at' => 'datetime',
            'revoquee_at' => 'datetime',
            'est_le_dossier_du_delegue' => 'boolean',
        ];
    }

    public function titulaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'titulaire_user_id');
    }

    public function delegue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_user_id');
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Acceptée et non révoquée. */
    public function estActive(): bool
    {
        return $this->acceptee_at !== null && $this->revoquee_at === null;
    }

    /** @param Builder<Delegation> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('acceptee_at')->whereNull('revoquee_at');
    }

    /** Le délégué a-t-il une délégation ACTIVE sur ce membre ? (contrôle d'autorisation QR). */
    public static function actifPour(int $delegueUserId, int $membreId): bool
    {
        return static::query()
            ->where('delegue_user_id', $delegueUserId)
            ->where('membre_id', $membreId)
            ->active()
            ->exists();
    }

    /**
     * Le délégué a-t-il une délégation ACTIVE emportant la LECTURE du dossier ?
     *
     * Distinct de {@see actifPour} à dessein : une délégation historique `qr_generation` ne doit
     * jamais ouvrir le dossier. Une méthode par droit, pas un booléen à interpréter.
     */
    public static function lecturePour(int $delegueUserId, int $membreId): bool
    {
        return static::query()
            ->where('delegue_user_id', $delegueUserId)
            ->where('membre_id', $membreId)
            ->whereIn('droits', self::DROITS_LECTURE)
            ->active()
            ->exists();
    }
}
