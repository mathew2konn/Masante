<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Praticien réservable d'une structure (Module 3 / F3.5, Analyse_Delta_RDV N5). Annuaire
 * professionnel public, non sensible : rattaché à UN service. `tarif_consultation` est indicatif
 * (aucun règlement). Configuration par les gestionnaires : Module 4.
 *
 * Module 5 / 5.6 — `user_id` relie la fiche à un compte du portail : c'est ce lien, posé par le
 * gestionnaire, qui permet à un médecin désigné référent d'ouvrir réellement le dossier (voie 2).
 * Sans lien, la fiche reste désignable mais son titulaire ne consulte pas en ligne.
 *
 * ═══ P6.5a — LA FICHE DEVIENT UNE IDENTITÉ PROFESSIONNELLE (CDC_09 §5.2) ═══
 *
 * Jusqu'ici cette table portait neuf colonnes utiles : de quoi remplir une vitrine, pas de quoi
 * identifier un professionnel. Elle porte désormais le numéro national, la profession, l'ordre et
 * surtout l'AUTORISATION D'EXERCER — le bloc que le §5.4 interrogera avant chaque signature.
 *
 * LE NOM DE LA TABLE NE CHANGE PAS. CDC_04 §5.2 l'appelle `professionnels_sante` ; on garde
 * `medecins`, comme P6.4a a gardé `structures_sanitaires` là où le corpus disait `etablissements`.
 * Renommer ferait migrer 29 fichiers pour un gain de vocabulaire (ADR-024).
 *
 * AUCUN ACCESSEUR « autorisation valide ? » ICI, et c'est délibéré. Ce jugement est celui du §5.4,
 * il doit être rendu en un seul endroit, journalisé quand il refuse, et testé comme tel : il vivra
 * dans une classe de règles pure en P6.5b. Le poser aussi sur le modèle créerait deux vérités —
 * la faute que P6.4a a évitée en refusant de recopier la note d'avis dans le référentiel.
 */
class Medecin extends Model
{
    protected $table = 'medecins';

    /**
     * `numero_professionnel` et `pays_code` sont VOLONTAIREMENT ABSENTS : un client ne choisit pas
     * son identifiant national, il le reçoit d'`AttributeurNumeroProfessionnel` (précédent exact
     * de `identifiant_national` en P6.4a). Les y ajouter rouvrirait la porte du mass assignment
     * que CDC_10 §5 ferme.
     */
    protected $fillable = [
        'structure_id',
        'service_id',
        'user_id',
        'titre',
        'nom',
        'prenom',
        'sexe',
        'date_naissance',
        'specialite',
        // P6.8a — le terme du vocabulaire national. `specialite` (le libellé) reste, parce que
        // l'annuaire de P3/P4 sérialise ce modèle et que ces modules sont validés G5 ; ce qui
        // change, c'est que le portail l'écrit désormais D'APRÈS le référentiel au lieu de le
        // recevoir du formulaire. Voir la note de `ServiceEtablissement` sur `$fillable`.
        'specialite_id',
        'profession',
        'sous_specialite',
        'ordre_professionnel',
        'numero_ordre',
        'autorisation_numero',
        'autorisation_statut',
        'autorisation_delivree_le',
        'autorisation_expire_le',
        'universite',
        'annee_diplome',
        'experience_annees',
        'telephone',
        'email',
        'biographie',
        'langues_json',
        'consultation_en_ligne',
        'consultation_physique',
        'tarif_consultation',
        'actif',
    ];

    /** Champs dérivés exposés au mobile (choix d'un référent dans l'annuaire). */
    protected $appends = ['nom_complet', 'consulte_en_ligne'];

    protected function casts(): array
    {
        return [
            'tarif_consultation'       => 'integer',
            'actif'                    => 'boolean',
            'date_naissance'           => 'date',
            'autorisation_delivree_le' => 'date',
            'autorisation_expire_le'   => 'date',
            'annee_diplome'            => 'integer',
            'experience_annees'        => 'integer',
            'langues_json'             => 'array',
            'consultation_en_ligne'    => 'boolean',
            'consultation_physique'    => 'boolean',
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

    /** Le terme du vocabulaire national qu'exerce ce praticien (P6.8a). */
    public function specialiteReferencee(): BelongsTo
    {
        return $this->belongsTo(SpecialiteMedicale::class, 'specialite_id');
    }

    /**
     * Les établissements où ce professionnel exerce (§5.2, pluriel — P6.5a).
     *
     * `structure_id` demeure l'exercice PRINCIPAL, parce que P3, P4 et les référents s'appuient
     * dessus et sont validés G5. Cette relation le complète : elle ne le remplace pas.
     */
    public function exercices(): HasMany
    {
        return $this->hasMany(ExerciceProfessionnel::class, 'medecin_id');
    }

    /** Diplômes (CDC_04 §5.2 ; §5.2 les dit optionnels). */
    public function diplomes(): HasMany
    {
        return $this->hasMany(DiplomeProfessionnel::class, 'medecin_id');
    }
}
