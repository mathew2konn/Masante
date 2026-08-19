<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P10a — Vers quelle(s) spécialité(s) un symptôme oriente, et dans quel ordre (CDC_05 §5).
 *
 * Une ligne par (symptôme, spécialité) : c'est ce qui rend représentable
 * « Cardiologie / Urgences », que la colonne `specialite_hint` portait dans une seule chaîne.
 *
 * `rang` porte la PRIORITÉ, qui vivait jusqu'ici dans un `str_contains($h, 'urgenc')` du service —
 * une règle médicale en dur (CDC_00 §4) qui dépendait de l'orthographe d'un libellé modifiable au
 * portail. Ici, elle est une donnée gouvernée par le §10.
 */
class SymptomeSpecialite extends Model
{
    protected $table = 'symptome_specialites';

    protected $fillable = [
        'symptome_id',
        'specialite_id',
        'rang',
        'sexe_requis',
    ];

    protected function casts(): array
    {
        return [
            'rang' => 'integer',
        ];
    }

    public function symptome(): BelongsTo
    {
        return $this->belongsTo(Symptome::class);
    }

    public function specialite(): BelongsTo
    {
        return $this->belongsTo(SpecialiteMedicale::class, 'specialite_id');
    }
}
