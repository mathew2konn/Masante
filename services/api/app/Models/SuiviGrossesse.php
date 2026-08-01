<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suivi de grossesse d'un membre (CdC §8 `suivi_grossesse`, FN4).
 *
 *  - `semaine_actuelle` n'est PAS stockée : accessor calculé depuis la DDG (toujours exact,
 *    pas de cron). Sérialisée dans le JSON via `$appends` → le mobile ne voit aucun écart vs CdC.
 *  - `consultations_json` : append-only via l'endpoint dédié ; le client n'écrit jamais le
 *    tableau entier (il n'est pas dans `$fillable`).
 *  - `membre_id` hors `$fillable` (piège connu du carnet) : création via la relation du membre.
 */
class SuiviGrossesse extends Model
{
    protected $table = 'suivi_grossesse';

    /** Durée théorique d'une grossesse : 280 jours (40 SA) après la DDG. */
    public const DUREE_JOURS = 280;

    /** Borne haute du calcul de SA (43 SA = terme dépassé, on n'affiche pas au-delà). */
    public const SEMAINE_MAX = 43;

    public const STATUTS = ['en_cours', 'termine', 'interruption'];

    /** Plafond d'entrées dans l'historique des consultations (borne le blob JSON). */
    public const MAX_CONSULTATIONS = 30;

    protected $fillable = [
        'date_debut_grossesse',
    ];

    protected $appends = ['semaine_actuelle'];

    protected function casts(): array
    {
        return [
            'date_debut_grossesse' => 'date',
            'date_terme_prevue'    => 'date',
            'consultations_json'   => 'array',
        ];
    }

    /**
     * Semaine d'aménorrhée EN COURS (1 → 43) : jours écoulés depuis la DDG ÷ 7, +1.
     * À J+70 (10 semaines révolues) on est dans la 11ᵉ semaine. Null si le suivi est clos
     * (la notion de « semaine actuelle » n'a plus de sens après la clôture).
     */
    public function getSemaineActuelleAttribute(): ?int
    {
        if ($this->statut !== 'en_cours' || $this->date_debut_grossesse === null) {
            return null;
        }

        $jours = (int) $this->date_debut_grossesse->startOfDay()->diffInDays(now()->startOfDay());

        return min(self::SEMAINE_MAX, max(1, intdiv($jours, 7) + 1));
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Rappels CPN générés automatiquement pour ce suivi (FK posée par le contrôleur). */
    public function rappelsCpn(): HasMany
    {
        return $this->hasMany(Rappel::class, 'suivi_grossesse_id');
    }
}
