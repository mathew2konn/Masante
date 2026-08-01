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
        'cmu_numero',
        'cmu_statut',
        'cmu_validite',
    ];

    protected $hidden = [
        'matricule_ivs',
        'user_id',
        // F2.3 — le numéro CMU complet ne quitte JAMAIS le serveur (chiffré au repos ET caché) :
        // seul `cmu_numero_masque` (accessor) est exposé. §5.2 Sécurité (exposition minimale).
        'cmu_numero',
        // Profil — chemin de stockage interne de la photo : jamais exposé (comme `matricule_ivs`).
        // Le client utilise `a_photo` puis l'endpoint dédié. `photo_url` est renseigné côté serveur.
        'photo_url',
    ];

    /** Champs dérivés ajoutés aux sérialisations JSON (numéro CMU masqué + présence d'une photo). */
    protected $appends = ['cmu_numero_masque', 'a_photo'];

    /** Profil — indique si le membre a une photo (le client charge alors l'endpoint dédié). */
    public function getAPhotoAttribute(): bool
    {
        return $this->photo_url !== null;
    }

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'cmu_validite'   => 'date',
            'cmu_numero'     => 'encrypted',
        ];
    }

    /**
     * F2.3 — Numéro CMU masqué (`•••• •••• 1234` : seuls les 4 derniers chiffres visibles).
     * Lit le numéro déchiffré en interne mais n'expose que la fin, jamais le numéro complet.
     */
    public function getCmuNumeroMasqueAttribute(): ?string
    {
        $numero = $this->cmu_numero;

        if (! $numero) {
            return null;
        }

        return '•••• •••• '.substr($numero, -4);
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

    /** Module 5 / FN4 — Suivis de grossesse (en cours + historique clos). */
    public function suivisGrossesse(): HasMany
    {
        return $this->hasMany(SuiviGrossesse::class, 'membre_id');
    }

    /** Module 5 / FN5 — Journal de bord des mesures (glycémie, tension, poids…). */
    public function mesuresSante(): HasMany
    {
        return $this->hasMany(MesureSante::class, 'membre_id');
    }

    /** Module 5 / 5.6 — Désignations de médecin référent (active + historique révoqué, voie 2). */
    public function referents(): HasMany
    {
        return $this->hasMany(Referent::class, 'membre_id');
    }
}
