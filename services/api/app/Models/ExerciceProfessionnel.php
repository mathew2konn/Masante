<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lieu d'exercice d'un professionnel de santé (CDC_09 §5.2, « établissements d'exercice »).
 *
 * P6.5a, décision propriétaire P2. La table est ADDITIVE : `medecins.structure_id` reste en place
 * — il est NOT NULL et lu par P3 (annuaire), P4 (rendez-vous) et la voie du médecin référent, tous
 * validés G5 — et devient l'exercice PRINCIPAL, doublé ici par une ligne `est_principal = true`.
 *
 * CETTE REDONDANCE EST ASSUMÉE ET ELLE A UN GARDIEN. La supprimer d'un côté casserait des modules
 * G5, de l'autre laisserait le référentiel national incapable de dire où exerce un professionnel.
 * C'est la commande de backfill qui garantit que les deux disent la même chose, et le contrôle
 * qualité du référentiel qui le signale si elles divergent.
 */
class ExerciceProfessionnel extends Model
{
    protected $table = 'professionnel_etablissement';

    protected $fillable = [
        'medecin_id',
        'structure_id',
        'service_id',
        'est_principal',
        'actif',
        'debut_le',
        'fin_le',
    ];

    protected function casts(): array
    {
        return [
            'est_principal' => 'boolean',
            'actif'         => 'boolean',
            'debut_le'      => 'date',
            'fin_le'        => 'date',
        ];
    }

    public function professionnel(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceEtablissement::class, 'service_id');
    }
}
