<?php

namespace App\Models;

use App\Support\StatutCommission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Commission prélevée par MaSanté sur une transaction patient (facturation partenaire).
 *
 * ═══ L'ÉGALITÉ QUI FAIT LE REÇU TRANSPARENT ═══
 *   montant_brut = frais_passerelle + frais_prestataire + montant_commission + montant_net_structure
 * C'est elle qu'on montre à un partenaire qui demande où est passé son argent : chaque franc entre
 * l'encaissement et son net est nommé. Un test la vérifie.
 *
 * ═══ CE QUI EST FIGÉ, ET POURQUOI ═══
 * `taux_bps_applique` et `volume_cumule_au_calcul` sont enregistrés à l'instant du calcul. On ne
 * recalcule JAMAIS une commission passée à partir du barème courant : le barème a pu changer, le
 * volume du mois a continué de monter, et la commission cesserait d'être reproductible.
 *
 * `frais_prestataire` est le montant RÉEL restitué par le microservice de paiement — jamais une
 * reconstitution locale du type « 100 F + 1 % ». Reconstituer produirait des écarts au franc que
 * personne ne saurait expliquer, et casserait précisément le reçu ci-dessus.
 *
 * ═══ `reference_interne_paiement` PORTE L'IDEMPOTENCE ═══
 * C'est la clé transmise par le microservice Java (`MS-{structure}-{ULID}`). Sa contrainte UNIQUE
 * en base est le garde-fou : un webhook rejoué, une relance réseau ou un renvoi du prestataire ne
 * doivent pas créer une seconde commission sur le même encaissement. La garantie est déclarative —
 * elle tient même si un futur appelant oublie de vérifier avant d'insérer.
 *
 * AUCUN CALCUL ICI : sélectionner un palier, appliquer un taux, rattacher à une facture mensuelle
 * relèvent du service de commission, hors de ce lot.
 */
class CommissionTransaction extends Model
{
    protected $table = 'commissions_transaction';

    protected $fillable = [
        'structure_sanitaire_id',
        'facture_patient_id',
        'facture_partenaire_id',
        'reference_geniuspay',
        'reference_interne_paiement',
        'montant_brut',
        'frais_passerelle',
        'frais_prestataire',
        'taux_bps_applique',
        'volume_cumule_au_calcul',
        'montant_commission',
        'montant_net_structure',
        'devise',
        'statut',
        'date_transaction',
    ];

    protected function casts(): array
    {
        return [
            'montant_brut' => 'integer',
            'frais_passerelle' => 'integer',
            'frais_prestataire' => 'integer',
            'taux_bps_applique' => 'integer',
            'volume_cumule_au_calcul' => 'integer',
            'montant_commission' => 'integer',
            'montant_net_structure' => 'integer',
            'statut' => StatutCommission::class,
            'date_transaction' => 'datetime',
        ];
    }

    /** @return BelongsTo<StructureSanitaire, $this> */
    public function structureSanitaire(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class);
    }

    /** La transaction patient qui a produit cette commission. @return BelongsTo<FacturePatient, $this> */
    public function facturePatient(): BelongsTo
    {
        return $this->belongsTo(FacturePatient::class);
    }

    /** La facture partenaire qui la porte. @return BelongsTo<FacturePartenaire, $this> */
    public function facturePartenaire(): BelongsTo
    {
        return $this->belongsTo(FacturePartenaire::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $commission) {
            // Valeur BRUTE d'AVANT modification (voir les autres modèles du lot).
            if ($commission->getRawOriginal('statut') === StatutCommission::FACTUREE->value) {
                throw new RuntimeException(
                    'Une commission FACTUREE ne se modifie plus : elle est portée par une facture '
                    .'déjà émise au partenaire. La corriger ici la ferait diverger du total facturé.'
                );
            }
        });
    }
}
