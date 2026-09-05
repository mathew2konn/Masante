<?php

namespace App\Models;

use App\Services\ServiceNotification;
use App\Support\MomentPaiement;
use App\Support\StatutFacturePatient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Facture adressée au patient par l'établissement (facturation patient).
 *
 * ═══ CE MODÈLE FAIT FOI POUR LE RÈGLEMENT D'UN ACTE ═══
 * Le projet porte déjà une table `paiements`, née avec le flux rendez-vous du Module 3, dont
 * l'encaissement est SIMULÉ (statut « payé » d'office, référence de transaction factice). Deux
 * réponses possibles à « cet acte a-t-il été réglé ? » est un piège à retardement — d'où la
 * décision : c'est ici que la question se pose, et `paiements` n'est plus interrogé pour cela.
 * Ce lot ne modifie pas `paiements` et ne migre rien.
 *
 * `rendez_vous_id` est le POINT D'ATTERRISSAGE de cette reprise, posé maintenant pour qu'elle
 * n'exige pas plus tard une migration sur une table déjà remplie. Nullable : une facture peut
 * naître d'un acte sans rendez-vous. Personne ne l'écrit encore.
 *
 * ═══ LE PAYEUR N'EST PAS TOUJOURS LE SOIGNÉ ═══
 * `patient_id` est le titulaire du compte, celui qui doit l'argent ; `beneficiaire_id` est le
 * membre de la famille qui a reçu l'acte. Les confondre rendrait le carnet familial infacturable.
 *
 * `relance_envoyee_le` : UNE SEULE relance, jamais deux (R18). L'horodatage EST le garde-fou — un
 * compteur autoriserait la deuxième par simple oubli de le lire.
 */
class FacturePatient extends Model
{
    protected $table = 'factures_patient';

    protected $fillable = [
        'structure_sanitaire_id',
        'patient_id',
        'beneficiaire_id',
        'rendez_vous_id',
        'reference',
        'facture_geniuspay_id',
        'moment_paiement',
        'montant_brut',
        'tarif_source',
        'montant_pris_en_charge_cmu',
        'montant_reste_a_charge',
        'devise',
        'statut',
        'paiement_en_ligne_autorise',
        'date_emission',
        'date_echeance',
        'date_reglement',
        'relance_envoyee_le',
    ];

    protected function casts(): array
    {
        return [
            'moment_paiement' => MomentPaiement::class,
            'montant_brut' => 'integer',
            'montant_pris_en_charge_cmu' => 'integer',
            'montant_reste_a_charge' => 'integer',
            'statut' => StatutFacturePatient::class,
            'paiement_en_ligne_autorise' => 'boolean',
            'date_emission' => 'datetime',
            'date_echeance' => 'date',
            'date_reglement' => 'datetime',
            'relance_envoyee_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<StructureSanitaire, $this> */
    public function structureSanitaire(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class);
    }

    /** Le titulaire du compte : celui qui doit l'argent. @return BelongsTo<User, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /** Le membre de la famille qui a reçu l'acte, s'il diffère du titulaire. @return BelongsTo<MembreFamille, $this> */
    public function beneficiaire(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'beneficiaire_id');
    }

    /** @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** @return HasMany<LigneFacturePatient, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFacturePatient::class);
    }

    /** @return HasMany<CommissionTransaction, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionTransaction::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $facture) {
            // Valeur BRUTE d'AVANT modification : `getOriginal()` applique les casts et rendrait
            // tantôt un enum tantôt une chaîne selon le chemin d'écriture.
            if ($facture->getRawOriginal('statut') === StatutFacturePatient::PAYEE->value) {
                throw new RuntimeException(
                    'Une facture patient PAYEE ne se modifie plus. Une correction s\'écrit par une '
                    .'nouvelle pièce, jamais en réécrivant une facture réglée.'
                );
            }
        });

        // Lot 9 (post-facturation) — « le patient est notifié d'une nouvelle facture » est une
        // garantie qui doit tenir quel que soit le CHEMIN de création (aujourd'hui aucun n'existe
        // encore pour A_REGLER — voir Phase 0 du lot 9 — mais un futur flux de paiement en ligne en
        // ouvrira un). Un crochet de modèle la tient pour tout appelant à venir, au lieu de compter
        // sur chacun pour se souvenir d'appeler le service de notification.
        static::created(function (self $facture) {
            if ($facture->statut === StatutFacturePatient::A_REGLER) {
                try {
                    app(ServiceNotification::class)->facturePatientEmise($facture);
                } catch (\Throwable $e) {
                    // Un tiers (ici : une notification) n'a jamais le droit de mettre en péril
                    // l'écriture d'une facture — même précédent que le push de P7-D1. Le
                    // garde-fou de contenu (lot 9) PEUT lever ici (libellé interdit détecté) :
                    // on le journalise, on ne fait jamais échouer la facture pour autant.
                    report($e);
                }
            }
        });
    }
}
