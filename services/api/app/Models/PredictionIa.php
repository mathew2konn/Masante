<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P10c-2-i (F10) — Traçabilité d'un appel à `triage-service` (CDC_05 §9.2 ; CDC_04 §115/§123).
 *
 * PAS ENCORE DANS LE RÉGIME `ProtocoleApplication` (append-only, chaîne hachée), et c'est
 * délibéré : cette table ne porte aujourd'hui aucun contenu clinique (`probabilite`/`facteurs_json`/
 * `explication_json` restent NULS tant qu'aucun modèle n'existe, F5/F6). Durcir un journal vide
 * serait le socle à vide refusé par P6.3-D3. Le durcissement complet est différé à P10c-3, quand
 * une explication réelle nommera pour la première fois des valeurs cliniques — voir la migration.
 */
class PredictionIa extends Model
{
    protected $table = 'predictions_ia';

    public $timestamps = false;

    protected $fillable = [
        'triage_id',
        'modele_version',
        'mode',
        'motif_degradation',
        'latence_ms',
        'probabilite',
        'facteurs_json',
        'explication_json',
        'confiance',
        'limites',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'facteurs_json' => 'array',
            'explication_json' => 'array',
            'cree_le' => 'datetime',
        ];
    }
}
