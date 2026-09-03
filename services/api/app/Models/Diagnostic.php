<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diagnostic posé pendant une consultation (B2-b, CDC_11 §5.2, CDC_04 §103).
 *
 * DISTINCT D'UN ANTÉCÉDENT, et ce n'est pas une nuance de vocabulaire : un antécédent SUIT le
 * patient et pèse sur ses triages futurs (`antecedents.impact_triage`), un diagnostic DATE d'un
 * épisode. Le passage de l'un à l'autre est un acte délibéré du médecin (`antecedent_id`).
 *
 * `libelle` porte les mots du médecin et n'est JAMAIS réécrit par le serveur ; le lien au
 * référentiel s'ajoute à côté, avec son code et son libellé FIGÉS (patron P6.8c).
 */
class Diagnostic extends Model
{
    /**
     * `maladie_id` est délibérément absent : le lien est posé par `ServiceLienMaladie`, qui relit
     * le référentiel et fige code et libellé. L'y mettre laisserait un client déclarer lui-même
     * ce que le serveur doit vérifier — la faute refermée sur `medecin_nom` (P6.5a), `source`
     * (P7-C), `obligatoire` (P6.8b) et `provenance` (P6.8d).
     */
    protected $fillable = [
        'libelle',
    ];

    protected function casts(): array
    {
        return [
            'libelle' => 'encrypted',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }

    public function maladie(): BelongsTo
    {
        return $this->belongsTo(Maladie::class, 'maladie_id');
    }

    /** L'antécédent créé par promotion, s'il l'a été et s'il existe encore. */
    public function antecedent(): BelongsTo
    {
        return $this->belongsTo(Antecedent::class, 'antecedent_id');
    }

    /** Ce diagnostic a-t-il déjà été inscrit aux antécédents du patient ? */
    public function estPromu(): bool
    {
        return $this->antecedent_id !== null;
    }

    /** Rattaché au référentiel national, ou seulement écrit en toutes lettres ? */
    public function estCode(): bool
    {
        return $this->maladie_code !== null;
    }
}
