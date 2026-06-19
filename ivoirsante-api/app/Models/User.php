<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

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
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'nom',
        'prenom',
        'telephone',
        'email',
        'password',
        'commune',
        'contact_urgence_nom',
        'contact_urgence_tel',
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
            'password' => 'hashed',
        ];
    }

    /** Le téléphone est-il vérifié (compte au moins de niveau « base » utilisable) ? */
    public function telephoneEstVerifie(): bool
    {
        return $this->telephone_verified_at !== null;
    }
}
