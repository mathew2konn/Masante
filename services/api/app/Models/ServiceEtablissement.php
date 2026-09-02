<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Service médical d'une structure (CdC §8.4). Configuration par les gestionnaires : Module 4.
 *
 * ═══ P6.8a — `specialite` N'EST PLUS UN TEXTE LIBRE ═══
 *
 * Le commentaire d'origine annonçait ici que `specialite` « permet le matching avec la spécialité
 * déduite par le triage (F1.5) ». C'ÉTAIT FAUX, et le G0 de P6.8 l'a établi : le triage produit des
 * libellés (« ORL (Oto-Rhino-Laryngologie) »), cette colonne porte des codes (« orl »), et
 * l'annuaire les compare en égalité exacte. Le rapprochement n'a jamais pu aboutir.
 *
 * Ce que P6.8a change : la colonne ne peut plus recevoir n'importe quel mot en minuscules — elle
 * porte un code du vocabulaire national (`specialite_id` le désigne). Ce qu'elle ne change PAS : le
 * branchement du triage, qui appartient à P10 puisqu'il refond déjà le triage.
 */
class ServiceEtablissement extends Model
{
    protected $table = 'services_etablissement';

    protected $fillable = [
        'structure_id',
        'nom_service',
        'specialite',
        // Le chemin d'écriture du portail est une assignation de masse : sans cette clé, le
        // rattachement serait silencieusement ignoré par Eloquent (piège de P6.7b). La garantie
        // ne tient donc pas à `$fillable` mais aux règles de validation et au contrôleur, qui
        // RÉSOLVENT le terme au lieu de croire le client — chacun avec son vecteur de test.
        'specialite_id',
        'actif',
        // B1-a — le tarif de consultation se déplace ici (D3, plan G1 B1) : jusqu'ici porté par le
        // médecin, avec repli sur le plancher de la structure. `RecuRdvService::tarifPour()` lit
        // cette colonne en premier et trace la source retenue sur la facture.
        'tarif_consultation_cfa',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'tarif_consultation_cfa' => 'integer',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    /** Le terme du vocabulaire national que ce service déclare exercer (P6.8a). */
    public function specialiteReferencee(): BelongsTo
    {
        return $this->belongsTo(SpecialiteMedicale::class, 'specialite_id');
    }

    public function disponibilites(): HasMany
    {
        return $this->hasMany(DisponibiliteJour::class, 'service_id');
    }

    /** Praticiens réservables du service (F3.5). */
    public function medecins(): HasMany
    {
        return $this->hasMany(Medecin::class, 'service_id');
    }

    /** Agents de garde affectés à ce service (4.3). */
    public function agents(): HasMany
    {
        return $this->hasMany(User::class, 'service_id');
    }
}
