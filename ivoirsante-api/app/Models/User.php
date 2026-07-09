<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Compte utilisateur principal (CdC §8.1 + doc Identification).
 *
 * Identifiant de connexion = `telephone` (e-mail optionnel). Deux niveaux de compte :
 * `base` (téléphone vérifié par OTP) et `verifie` (identité confirmée par CMU/CNI, pour
 * les fonctions exposant le dossier médical). $fillable explicite (§8.2 Sécurité —
 * anti mass-assignment) ; secrets cachés des sérialisations JSON.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'nom',
        'prenom',
        'date_naissance',
        'telephone',
        'email',
        'password',
        'commune',
        'contact_urgence_nom',
        'contact_urgence_tel',
        'structure_id',
        'actif',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'telephone_verified_at' => 'datetime',
            'compte_verifie_at' => 'datetime',
            'date_naissance' => 'date',
            'password' => 'hashed',
            'actif' => 'boolean',
        ];
    }

    /** Le téléphone est-il vérifié (compte au moins de niveau « base » utilisable) ? */
    public function telephoneEstVerifie(): bool
    {
        return $this->telephone_verified_at !== null;
    }

    /**
     * Le compte est-il au palier « vérifié » (identité confirmée par CMU/CNI) ?
     * Conditionne les fonctions justificatives (F2.3 — présentation de la carte CMU).
     */
    public function compteEstVerifie(): bool
    {
        return $this->compte_verifie_at !== null;
    }

    /** Membres de la famille rattachés à ce compte (CdC §5.2, max 5 — F2.2). */
    public function membresFamille(): HasMany
    {
        return $this->hasMany(MembreFamille::class);
    }

    /** Établissement de rattachement d'un compte STAFF (gestionnaire/agent). NULL pour patients/admin (4.2). */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }
}
