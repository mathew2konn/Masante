<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * P10c-2-i (F4) — Une ligne du jeu d'apprentissage §5.5.4/§7.2 : PSEUDONYMISÉE, jamais anonyme.
 *
 * Voir la migration pour la justification complète. En bref : `triage_id` y reste pour
 * l'idempotence et la traçabilité §9.2, donc quiconque a la base peut remonter au patient via
 * `triages.membre_id` — l'anonymisation n'est effective qu'à l'export (P10c-3), qui retire ce lien.
 */
class JeuDonneesEntrainement extends Model
{
    protected $table = 'jeux_donnees_entrainement';

    public $timestamps = false;

    protected $fillable = [
        'triage_id',
        'age',
        'sexe',
        'symptomes_json',
        'temperature',
        'pouls',
        'saturation_o2',
        'tension_systolique',
        'tension_diastolique',
        'poids',
        'duree_jours',
        'intensite',
        'grossesse',
        // P10c-3-i (F14) — la feature déjà gouvernée par P10b-3-ii, reprise de `triages` au moment
        // du retour (voir `ServiceRetourTriage::alimenterJeuApprentissage()`).
        'score_antecedents',
        // P10c-3-ii (F32) — cibles futures. Déclarées ICI parce qu'une colonne absente du
        // `$fillable` est écartée par Eloquent SANS UN MOT : c'est le défaut trouvé deux fois de
        // suite en P10c-3-i, sur ce modèle et sur `Triage`. Une migration ne suffit jamais.
        'niveau_reel',
        'maladie_code',
        'specialite_code',
        'niveau_protocole',
        'label',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'symptomes_json' => 'array',
            'grossesse' => 'boolean',
            'cree_le' => 'datetime',
        ];
    }

    public function validation(): HasOne
    {
        return $this->hasOne(ValidationMedecin::class, 'jeu_id');
    }
}
