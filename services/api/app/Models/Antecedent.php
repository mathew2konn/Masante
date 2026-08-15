<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antécédent médical d'un membre (CdC §8.3, F2.4). `description` et `traitement_actuel`
 * chiffrés AES-256 au repos (§6 Sécurité). `impact_triage` alimente le score de triage (F1.3).
 */
class Antecedent extends Model
{
    protected $fillable = [
        'type',
        // P6.8c — le lien FACULTATIF au référentiel des maladies, et les deux valeurs figées.
        //
        // `$fillable` PARCE QUE le chemin d'écriture est une assignation de masse : la garantie ne
        // repose pas sur cette liste mais sur les règles de validation ET sur `ServiceLienMaladie`,
        // chacune avec son vecteur (leçon de P6.7b, où retirer les noms figés du `$fillable` aurait
        // cassé le chemin sans rien garantir de plus).
        'maladie_id',
        'maladie_code',
        'maladie_libelle',
        'description',
        'date_diagnostic',
        'medecin_nom',
        'structure_sanitaire',
        'traitement_actuel',
        'impact_triage',
        'added_by',
        'source',
    ];

    /** F2.13 — défaut aligné sur la colonne BDD, pour que la réponse de création porte déjà la provenance. */
    protected $attributes = ['source' => 'patient'];

    protected function casts(): array
    {
        return [
            'description'       => 'encrypted',
            'traitement_actuel' => 'encrypted',
            'date_diagnostic'   => 'date',
            'impact_triage'     => 'integer',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /**
     * La maladie du référentiel national, quand l'auteur de la saisie en a désigné une.
     *
     * FACULTATIVE et JAMAIS DEVINÉE : le serveur ne rapproche aucun texte libre d'un code — ce serait
     * un diagnostic posé par une machine (CDC_00 §4). Et `description` n'est jamais réécrite : le
     * lien s'ajoute À CÔTÉ des mots du patient (leçon P6.7b).
     */
    public function maladie(): BelongsTo
    {
        return $this->belongsTo(Maladie::class, 'maladie_id');
    }
}
