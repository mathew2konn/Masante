<?php

namespace App\Models;

use App\Support\StatutConsultation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Acte de soin mené par un professionnel (B2-a, CDC_11 §5.2, CDC_04 §12 étape 7).
 *
 * Distincte du JOURNAL D'ACCÈS (`acces_dossier`) qui l'a rendue possible : celui-ci trace qui a
 * ouvert quel dossier, quand et par quelle voie — un registre de surveillance, réservé au
 * propriétaire du dossier (P7-D2) et immuable. Celle-ci porte l'acte lui-même, elle se rédige
 * puis se clôture. Le lien entre les deux est un IDENTIFIANT, jamais une clé étrangère
 * (ADR-042 D1) : supprimer une ligne de journal ne doit pas effacer un acte de soin.
 *
 * `soignant_nom` et `structure_nom` sont FIGÉS à l'ouverture par le serveur — jamais déclarés par
 * le client, jamais relus après coup (patron P6.6b/P6.7b/P7-D2) : une consultation dit qui l'a
 * menée et où, même si le compte est supprimé ou l'agent muté ensuite.
 */
class Consultation extends Model
{
    protected $fillable = [
        'motif',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutConsultation::class,
            'motif' => 'encrypted',
            'debutee_le' => 'datetime',
            'cloturee_le' => 'datetime',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    /**
     * Le compte qui a mené l'acte. `belongsTo` sans contrainte en base : la relation rend `null`
     * si le compte a été supprimé, et `soignant_nom` continue de dire qui c'était.
     */
    public function soignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soignant_user_id');
    }

    /** Les diagnostics posés pendant cette consultation (B2-b). */
    public function diagnostics(): HasMany
    {
        return $this->hasMany(Diagnostic::class, 'consultation_id');
    }

    /** Les observations consignées pendant cette consultation (Z-a : `notes_observations`). */
    public function observations(): HasMany
    {
        return $this->hasMany(NoteObservation::class, 'consultation_id');
    }

    /**
     * B5-a — les demandes d'examens prescrites pendant cette consultation.
     *
     * Identifiant SANS clé étrangère (ADR-042 D1) sur `demandes_analyses.consultation_id` : cette
     * relation reste une simple requête, elle ne garantit rien de plus que ce que
     * `EcritureSoignantService` a déjà posé à l'écriture.
     */
    public function demandesAnalyses(): HasMany
    {
        return $this->hasMany(DemandeAnalyse::class, 'consultation_id');
    }

    public function estEnCours(): bool
    {
        return $this->statut === StatutConsultation::EN_COURS;
    }
}
