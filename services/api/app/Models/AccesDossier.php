<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Journal d'audit des accès aux dossiers (CdC §8.1 ; Sécurité §10), conformité loi 2013-450.
 *
 * IMMUABLE : toute modification ou suppression après écriture est refusée (§10.2). Un journal
 * modifiable n'a aucune valeur probante. Idéalement, on retire aussi les droits UPDATE/DELETE
 * de cette table au compte MySQL applicatif (défense en profondeur).
 *
 * Journal en ajout seul : pas de `updated_at`.
 */
class AccesDossier extends Model
{
    protected $table = 'acces_dossier';

    public $timestamps = false;

    protected $fillable = [
        'membre_id',
        'agent_id',
        'token_qr_id',
        'type_acces',
        // Bris de glace (Note_Continuite §5.3) : justification saisie AVANT l'ouverture. Immuable
        // comme le reste du journal — un accès d'exception doit rester explicable après coup.
        'motif_urgence',
        // D2 — nom de l'établissement COPIÉ au moment de l'accès (jamais déduit après coup :
        // un agent qui change d'hôpital ne doit pas déplacer ses visites passées).
        'etablissement',
        // D2 — la ligne de clôture désigne son ouverture. Sans elle, les deux lignes d'un accès
        // référent ou d'urgence vitale (sans `token_qr_id`) ne se retrouvent que par devinette.
        'acces_ouverture_id',
        // P10c-2-i — le triage auquel le soignant a DÉCLARÉ que cette consultation répond
        // (§5.5.4). Un identifiant, sans clé étrangère : le journal survit au triage supprimé
        // (ADR-042 D1).
        'triage_id',
        // B1-c — le rendez-vous qui a rendu cet accès possible (voie `rdv_partage` seule).
        // Même statut que `triage_id` : un identifiant, sans clé étrangère (ADR-042 D1).
        'rendez_vous_id',
        'sections_consultees',
        'donnees_ajoutees',
        'ip_address',
        'duree_minutes',
    ];

    protected function casts(): array
    {
        return [
            'sections_consultees' => 'array',
            'donnees_ajoutees'    => 'array',
            'created_at'          => 'datetime',
        ];
    }

    /** Garantit l'immuabilité du journal (§10.2 Sécurité). */
    protected static function booted(): void
    {
        static::updating(fn () => abort(403, 'Journal d\'audit immuable.'));
        static::deleting(fn () => abort(403, 'Journal d\'audit immuable.'));
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /**
     * L'agent qui a accédé au dossier.
     *
     * `agent_id` n'a pas de clé étrangère (héritage du Module 2, où les agents n'existaient pas
     * encore) : la relation peut donc être vide si le compte a disparu. La fiche de parcours doit
     * le supporter sans se casser.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** La ligne d'ouverture, si celle-ci est une clôture (D2). */
    public function ouverture(): BelongsTo
    {
        return $this->belongsTo(self::class, 'acces_ouverture_id');
    }

    /** La ligne de clôture, si celle-ci est une ouverture (D2) — absente si la session ne s'est jamais fermée. */
    public function cloture(): HasOne
    {
        return $this->hasOne(self::class, 'acces_ouverture_id');
    }
}
