<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne d'examen demandé (B5-a, CDC_04 §109, CDC_09 §7.4).
 *
 * CE QUI EST EN CLAIR ET CE QUI NE L'EST PAS — question reposée, pas recopiée (L9) : l'identité de
 * l'examen est en clair (sans quoi ni le laboratoire ne sait quoi analyser, ni le catalogue ne sert
 * à rien), ce que cette personne doit faire avant le prélèvement reste chiffré.
 */
class DemandeAnalyseLigne extends Model
{
    protected $table = 'demande_analyse_lignes';

    /**
     * Le lien au référentiel et ses valeurs figées ne sont PAS ici : ils sont posés par le serveur
     * depuis `ProjecteurLignesDemande` (via `ServiceLienAnalyse`, P6.7a), comme sur
     * `demandes_analyses.analyses_json`. Un client qui les déclarerait choisirait ce que le
     * serveur doit vérifier (piège relevé par P6.7b sur les noms figés).
     */
    protected $fillable = [
        'libelle',
        'rang',
    ];

    protected function casts(): array
    {
        return [
            'conditions_prelevement' => 'encrypted',
        ];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeAnalyse::class, 'demande_id');
    }

    public function analyse(): BelongsTo
    {
        return $this->belongsTo(Analyse::class, 'analyse_id');
    }

    /** Rattachée au catalogue national, ou seulement écrite en toutes lettres ? */
    public function estCodee(): bool
    {
        return $this->code_national !== null;
    }
}
