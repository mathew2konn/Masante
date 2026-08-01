<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Document médical importé d'un membre (F2.10). Le blob est chiffré au repos ; `source` porte la
 * provenance (patient / medecin / structure, F2.13). `statut_antivirus` verrouille le téléchargement
 * (un document non `sain` n'est jamais servi). Rétention médicale via SoftDeletes.
 *
 * `uploaded_by_user_id` et `membre_id` sont attribués côté serveur (hors `$fillable`, anti mass-assignment).
 */
class DocumentMedical extends Model
{
    use SoftDeletes;

    protected $table = 'documents_medicaux';

    /** Catégories acceptées (miroir de l'ENUM de la migration). Source unique pour la validation. */
    public const CATEGORIES = [
        'certificat_medical',
        'fiche_sortie',
        'compte_rendu',
        'imagerie',
        'resultat_labo',
        'assurance',
        'ordonnance_externe',
        'autre',
    ];

    /** Provenances possibles (F2.13), miroir de l'ENUM `source`. */
    public const SOURCES = ['patient', 'medecin', 'structure'];

    protected $fillable = [
        'categorie',
        'titre',
        'nom_fichier_original',
        'fichier_url',
        'mime_type',
        'extension',
        'taille_octets',
        'hash_sha256',
        'statut_antivirus',
        'source',
        'date_document',
        'triage_id',
    ];

    protected function casts(): array
    {
        return [
            'taille_octets' => 'integer',
            'date_document' => 'date',
        ];
    }

    /** Un document non analysé/infecté n'est jamais téléchargeable. */
    public function estTelechargeable(): bool
    {
        return $this->statut_antivirus === 'sain';
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function triage(): BelongsTo
    {
        return $this->belongsTo(Triage::class, 'triage_id');
    }
}
