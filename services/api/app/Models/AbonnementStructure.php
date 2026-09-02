<?php

namespace App\Models;

use App\Support\MotifSuspension;
use App\Support\StatutAbonnement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contrat d'abonnement d'un établissement à un plan (facturation partenaire).
 *
 * ═══ L'ESSAI EST DE 30 JOURS POUR TOUS ═══
 * Règle R2 amendée le 26/08/2026. `duree_essai_jours` est historisée PAR abonnement, avec 30 pour
 * défaut de colonne : un changement futur de politique ne doit jamais rallonger ni raccourcir un
 * essai déjà consenti. Ne code 30 nulle part — ni ici, ni dans un service.
 *
 * `rang_signature` garde l'ordre d'arrivée des partenaires pour l'audit. Elle NE DÉTERMINE PLUS
 * AUCUNE DURÉE : l'offre de lancement à 90 jours pour les vingt premiers est abrogée.
 *
 * ═══ C'EST ICI QUE VIT L'ÉTAT COMMERCIAL, ET NULLE PART AILLEURS ═══
 * `date_bascule_palier0` et `motif_suspension` sont portés par le CONTRAT, jamais par
 * `structures_sanitaires`. La colonne `actif` de l'établissement est le commutateur ADMINISTRATIF
 * (fermeture, fraude, décision d'un administrateur) : y écrire une sanction d'impayé mêlerait deux
 * décisions qui n'ont ni le même auteur ni les mêmes conséquences — une structure fermée doit
 * disparaître, une structure suspendue pour impayé doit RESTER VISIBLE.
 *
 * Aucune transition n'est décidée ici : suspendre, réactiver, calculer une fin d'essai relèvent du
 * service de recouvrement (docs/REGLES_RECOUVREMENT_PARTENAIRE.md).
 */
class AbonnementStructure extends Model
{
    protected $table = 'abonnements_structure';

    protected $fillable = [
        'structure_sanitaire_id',
        'plan_tarifaire_id',
        'rang_signature',
        'duree_essai_jours',
        'date_debut',
        'date_fin_essai',
        'date_fin',
        'statut',
        'motif_suspension',
        'date_bascule_palier0',
    ];

    protected function casts(): array
    {
        return [
            'rang_signature' => 'integer',
            'duree_essai_jours' => 'integer',
            'date_debut' => 'date',
            'date_fin_essai' => 'date',
            'date_fin' => 'date',
            'statut' => StatutAbonnement::class,
            'motif_suspension' => MotifSuspension::class,
            'date_bascule_palier0' => 'datetime',
        ];
    }

    /** @return BelongsTo<StructureSanitaire, $this> */
    public function structureSanitaire(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class);
    }

    /** @return BelongsTo<PlanTarifaire, $this> */
    public function planTarifaire(): BelongsTo
    {
        return $this->belongsTo(PlanTarifaire::class);
    }
}
