<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P10c-3-i (F17/F20) — Un export ANONYMISÉ, prêt à entraîner (CDC_05 §7.2 ; CDC_13 §12).
 *
 * Contrairement à `JeuDonneesEntrainement` (pseudonymisé, `triage_id` conservé), `instantane_json`
 * ici ne porte plus AUCUN identifiant — voir la migration pour le détail de ce qui est retiré
 * (triage_id, âge exact, date exacte) et ce qui reste à précision clinique (constantes, symptômes).
 */
class ExportJeuEntrainement extends Model
{
    protected $table = 'exports_jeu_entrainement';

    public $timestamps = false;

    protected $fillable = [
        'pays_code',
        'numero_export',
        'instantane_json',
        'nb_lignes',
        'k_estime',
        'cree_par',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'instantane_json' => 'array',
            'cree_le' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(VersionModeleIa::class, 'export_id');
    }
}
