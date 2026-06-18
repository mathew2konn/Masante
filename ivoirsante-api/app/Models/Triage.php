<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Session de triage enregistrée (Module 1, F1.6 historique / F1.8 fiche).
 * $fillable explicite (§8.2 Sécurité). Données médicales non sensibles ici
 * (symptômes/score) ; les antécédents chiffrés viendront du carnet (Module 2).
 */
class Triage extends Model
{
    protected $table = 'triages';

    protected $fillable = [
        'user_id',
        'membre_id',
        'patient_nom',
        'patient_age',
        'patient_sexe',
        'symptomes_json',
        'reponses_json',
        'score_severite',
        'niveau',
        'specialite_requise',
        'recommandation_texte',
        'fiche_generee',
        'structure_visitee_id',
    ];

    protected $casts = [
        'symptomes_json' => 'array',
        'reponses_json' => 'array',
        'score_severite' => 'integer',
        'patient_age' => 'integer',
        'fiche_generee' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
