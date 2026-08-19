<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'service_id',
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

    /**
     * Le nom lisible par un humain, pour les journaux d'audit — SOURCE UNIQUE.
     *
     * ═══ P10b-1 — CE QUE SON ABSENCE AVAIT PRODUIT ═══
     *
     * Ce modèle porte `nom` et `prenom` ; il n'a **jamais** eu d'attribut `name`. Or
     * `JournalReferentiel` (P6.3) écrivait `$acteur?->name ?? 'Système'` : l'expression est
     * silencieusement `null`, et la chaîne d'audit du socle référentiel a enregistré
     * **« Système » pour chaque acteur humain depuis P6.3** — alors que son propre commentaire
     * pose que `acteur_nom` entre dans l'empreinte « parce que c'est ce nom-là qu'un humain lit
     * dans un audit ».
     *
     * Le défaut est sorti d'un vecteur de P10b-1, pas d'une relecture : la colonne
     * `protocole_validations.validateur_nom` est `NOT NULL`, et l'insertion a échoué là où le
     * journal, lui, se rabattait sans bruit sur un défaut.
     *
     * `JournalSignature` (P6.5b) avait résolu le problème correctement, mais **chez lui** : deux
     * implémentations du même besoin, dont une fausse. La logique remonte donc ici, à l'endroit
     * qui connaît l'identité — les trois journaux l'appellent, et ils ne peuvent plus diverger.
     *
     * Ne renvoie JAMAIS de chaîne vide : un journal d'audit qui ne désigne personne ne prouve
     * rien. Repli en cascade sur l'e-mail puis sur l'identifiant technique.
     */
    public function nomLisible(): string
    {
        $nom = trim(($this->prenom ?? '').' '.($this->nom ?? ''));

        return $nom !== '' ? $nom : ($this->email ?? 'Compte '.$this->id);
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

    /** Facteurs MFA du compte (TOTP…) — P1, CDC_10 §3.5. Enrôlés puis confirmés par l'utilisateur. */
    public function mfaFacteurs(): HasMany
    {
        return $this->hasMany(MfaFacteur::class);
    }

    /** Établissement de rattachement d'un compte STAFF (gestionnaire/agent). NULL pour patients/admin (4.2). */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    /** Service d'affectation d'un AGENT de garde (accès limité à son service, §5.4.2). NULL sinon (4.3). */
    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceEtablissement::class, 'service_id');
    }

    /**
     * Module 5 / 5.6 — Fiche de l'annuaire public correspondant à ce compte (voie 2 « référent »).
     * NULL pour tout compte qui n'est pas un praticien relié par son gestionnaire.
     */
    public function medecin(): HasOne
    {
        return $this->hasOne(Medecin::class, 'user_id');
    }

    /**
     * Périmètre d'action « dispo & RDV » (Module 4.4) : identifiants des services que ce compte peut
     * gérer. AGENT = son seul service (accès limité à son service, §5.4.2) ; GESTIONNAIRE = tous les
     * services de son établissement (superviseur) ; autres (admin/patient) = aucun.
     *
     * @return array<int, int>
     */
    public function servicesGeresIds(): array
    {
        if ($this->service_id !== null) {
            return [$this->service_id];
        }

        if ($this->structure_id !== null && $this->hasRole('gestionnaire_etablissement')) {
            return ServiceEtablissement::where('structure_id', $this->structure_id)->pluck('id')->all();
        }

        return [];
    }
}
