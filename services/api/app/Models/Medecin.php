<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Praticien réservable d'une structure (Module 3 / F3.5, Analyse_Delta_RDV N5). Annuaire
 * professionnel public, non sensible : rattaché à UN service. `tarif_consultation` est indicatif
 * (aucun règlement). Configuration par les gestionnaires : Module 4.
 *
 * Module 5 / 5.6 — `user_id` relie la fiche à un compte du portail : c'est ce lien, posé par le
 * gestionnaire, qui permet à un médecin désigné référent d'ouvrir réellement le dossier (voie 2).
 * Sans lien, la fiche reste désignable mais son titulaire ne consulte pas en ligne.
 */
class Medecin extends Model
{
    protected $table = 'medecins';

    protected $fillable = [
        'structure_id',
        'service_id',
        'user_id',
        'titre',
        'nom',
        'prenom',
        'specialite',
        'tarif_consultation',
        'actif',
    ];

    /** Champs dérivés exposés au mobile (choix d'un référent dans l'annuaire). */
    protected $appends = ['nom_complet', 'consulte_en_ligne'];

    protected function casts(): array
    {
        return [
            'tarif_consultation' => 'integer',
            'actif' => 'boolean',
        ];
    }

    /** « Dr Aya Koffi » — libellé unique, pour ne pas le recomposer dans chaque écran. */
    public function getNomCompletAttribute(): string
    {
        return trim("{$this->titre} {$this->prenom} {$this->nom}");
    }

    /**
     * Ce médecin peut-il réellement consulter le dossier s'il est désigné référent ? (5.6)
     * Faux tant que le gestionnaire n'a pas relié la fiche à un compte du portail : le patient
     * doit le savoir AVANT de désigner, sinon il croirait son dossier partagé alors qu'il ne l'est pas.
     */
    public function getConsulteEnLigneAttribute(): bool
    {
        return $this->user_id !== null;
    }

    /** Compte portail du praticien (5.6). NULL = fiche d'annuaire sans compte. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
