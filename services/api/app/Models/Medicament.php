<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 5 / 5.8 — Médicament du catalogue (CdC §8, FN7), devenu en P6.6a l'entrée du **référentiel
 * national des médicaments** (CDC_09 §6). Donnée PUBLIQUE et non sensible (un prix n'appartient à
 * personne) : aucun chiffrement, lecture ouverte.
 *
 * `code` et `pays_code` sont HORS `$fillable` : un client ne choisit pas l'identifiant national d'un
 * produit, il le reçoit ({@see App\Services\Medicament\AttributeurCodeMedicament}).
 *
 * AUCUNE VALEUR N'EST RECALCULÉE ICI, et c'est ce qui a permis de mettre la ligne entière sous
 * gouvernance. Les prix relevés par les citoyens et les ruptures signalées vivent dans
 * `prix_pharmacie`, une table séparée : contrairement à `structures_sanitaires` dont la
 * `note_moyenne` bouge à chaque avis (P6.4a), rien n'écrit ici en arrière-plan.
 */
class Medicament extends Model
{
    protected $table = 'medicaments';

    protected $fillable = [
        'nom_generique',
        'nom_commercial',
        'laboratoire',
        'forme',
        'dosage',
        'voie_administration',
        'categorie',
        'indications',
        'contre_indications',
        'effets_secondaires',
        'statut_marche',
        'prix_reference_cfa',
        'ordonnance_requise',
        'disponible_generique',
        'statut_generique',
        'cename_reference',
    ];

    protected $appends = ['libelle'];

    protected function casts(): array
    {
        return [
            'prix_reference_cfa'   => 'integer',
            'ordonnance_requise'   => 'boolean',
            'disponible_generique' => 'boolean',
        ];
    }

    /**
     * Les interactions déclarées, quel que soit le côté du couple où ce médicament figure.
     *
     * Le couple est stocké ORDONNÉ (`medicament_a_id < medicament_b_id`) pour rendre l'unicité
     * déclarative ; il faut donc interroger les deux colonnes. Ne pas le faire donnerait des
     * résultats corrects pour la moitié des médicaments seulement — et le défaut serait invisible
     * tant qu'on ne testerait qu'un seul sens.
     *
     * @return \Illuminate\Database\Eloquent\Builder<InteractionMedicamenteuse>
     */
    public function interactions()
    {
        return InteractionMedicamenteuse::query()
            ->where('medicament_a_id', $this->getKey())
            ->orWhere('medicament_b_id', $this->getKey());
    }

    /** Le produit ne doit plus être délivré (§6.5). Signalé au prescripteur, jamais bloquant. */
    public function estRetireDuMarche(): bool
    {
        return $this->statut_marche === 'retire';
    }

    /** « DOLIPRANE (paracétamol) » ou « paracétamol » : ce que le patient reconnaît en rayon. */
    public function getLibelleAttribute(): string
    {
        return $this->nom_commercial
            ? "{$this->nom_commercial} ({$this->nom_generique})"
            : $this->nom_generique;
    }

    /** Relevés de prix et de disponibilité, toutes pharmacies et toutes sources confondues. */
    public function releves(): HasMany
    {
        return $this->hasMany(PrixPharmacie::class, 'medicament_id');
    }
}
