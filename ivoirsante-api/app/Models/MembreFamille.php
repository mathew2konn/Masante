<?php

namespace App\Models;

use Database\Factories\MembreFamilleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Membre de la famille rattaché à un compte (CdC §5.2 / §8.1, F2.1).
 *
 * Sécurité :
 *  - `matricule_ivs` et `user_id` sont cachés des sérialisations JSON : le matricule interne
 *    ne doit jamais fuiter (§1, §2.1 Sécurité) ; l'isolation est garantie par la Policy.
 *  - `cmu_numero` est chiffré au repos (cast `encrypted`, AES-256 via APP_KEY — §6.1 Sécurité).
 *  - `$fillable` explicite (anti mass-assignment) ; `matricule_ivs` n'y figure pas : il est
 *    attribué par le serveur via MatriculeService, jamais par l'utilisateur.
 *
 * La règle « max 5 membres par compte » (F2.2) est appliquée à la validation (StoreMembreRequest).
 */
class MembreFamille extends Model
{
    /** @use HasFactory<MembreFamilleFactory> */
    use HasFactory;

    protected $table = 'membres_famille';

    protected $fillable = [
        'nom',
        'prenom',
        'date_naissance',
        'sexe',
        'groupe_sanguin',
        'photo_url',
        'cmu_numero',
        'cmu_statut',
        'cmu_validite',
    ];

    protected $hidden = [
        'matricule_ivs',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'cmu_validite'   => 'date',
            'cmu_numero'     => 'encrypted',
        ];
    }

    /** Compte propriétaire du membre. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Tokens QR dynamiques générés pour ce membre (2A.3). */
    public function tokensQr(): HasMany
    {
        return $this->hasMany(TokenQr::class, 'membre_id');
    }

    /** Journal d'audit des accès au dossier de ce membre (§10, loi 2013-450). */
    public function accesDossier(): HasMany
    {
        return $this->hasMany(AccesDossier::class, 'membre_id');
    }

    // --- Sections du carnet (2A.4) ---

    public function antecedents(): HasMany
    {
        return $this->hasMany(Antecedent::class, 'membre_id');
    }

    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class, 'membre_id');
    }

    public function ordonnances(): HasMany
    {
        return $this->hasMany(Ordonnance::class, 'membre_id');
    }

    public function resultatsAnalyses(): HasMany
    {
        return $this->hasMany(ResultatAnalyse::class, 'membre_id');
    }

    public function rappels(): HasMany
    {
        return $this->hasMany(Rappel::class, 'membre_id');
    }

    // --- Nouvelles sections du carnet (F2.10 → F2.12) ---

    public function documentsMedicaux(): HasMany
    {
        return $this->hasMany(DocumentMedical::class, 'membre_id');
    }

    public function contactsUrgence(): HasMany
    {
        return $this->hasMany(ContactUrgence::class, 'membre_id');
    }

    public function notesObservations(): HasMany
    {
        return $this->hasMany(NoteObservation::class, 'membre_id');
    }
}
