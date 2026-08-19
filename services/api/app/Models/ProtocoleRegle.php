<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une règle déclarative d'un protocole (CDC_08 §4.3a) : SI conditions ALORS actions.
 *
 * L'`ordre` EST une donnée clinique, pas un détail de tri : c'est lui qui fait primer
 * « drapeau rouge » sur « score entre 0 et 25 ». Il remplace le `str_contains` de P10a et le
 * `match` PHP de `TriageService::niveauDepuisScore()` — la priorité devient une donnée relue par
 * deux agents (§7) au lieu d'une exception enfouie dans une méthode privée.
 *
 * Le `libelle` n'est pas décoratif non plus : le §7 fait signer des médecins spécialistes. Leur
 * présenter `score >= 76` sans phrase reviendrait à leur faire signer du code.
 */
class ProtocoleRegle extends Model
{
    protected $table = 'protocole_regles';

    protected $fillable = ['version_id', 'ordre', 'libelle'];

    protected function casts(): array
    {
        return ['ordre' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProtocoleVersion::class, 'version_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ProtocoleCondition::class, 'regle_id')->orderBy('ordre');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ProtocoleAction::class, 'regle_id')->orderBy('ordre');
    }
}
