<?php

namespace App\Models;

use App\Support\StatutFacturePartenaire;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Facture mensuelle adressée à un établissement (facturation partenaire).
 *
 * ═══ LA RÈGLE DU SOLDE UNIQUE (D-E3) ═══
 * La facture porte UN SEUL montant à payer. `montant_abonnement` et `montant_commissions` sont de
 * la VENTILATION COMPTABLE — elles disent d'où vient le total, elles ne sont pas deux soldes qu'on
 * pourrait éteindre séparément. Le partenaire n'a qu'un total en face de lui : c'est ainsi qu'est
 * mise en œuvre la décision « les deux ou rien ».
 *
 * ═══ `montant_regle` N'EST PAS LA SOURCE DE VÉRITÉ ═══
 * La vérité, ce sont les lignes de `reglements_facture_partenaire`. Cette colonne est un cumul
 * dénormalisé entretenu pour la lecture. Si les deux divergent, ce sont les lignes qui ont raison.
 *
 * ═══ POURQUOI `solde` EST UN ACCESSEUR ET NON UNE COLONNE ═══
 * Le stocker créerait une TROISIÈME valeur à maintenir, donc une troisième occasion de se
 * contredire. Les deux accesseurs ci-dessous sont des PROJECTIONS de colonnes existantes, pas des
 * calculs métier : ils ne décident rien, n'imputent rien, ne changent aucun état. Ce sont les deux
 * seules dérivations autorisées dans tout ce lot.
 */
class FacturePartenaire extends Model
{
    protected $table = 'factures_partenaire';

    /**
     * Colonnes qui restent modifiables une fois la facture ÉMISE.
     *
     * Tout le reste — et notamment les trois montants — est figé à l'émission : une facture
     * opposable dont le total peut encore bouger n'est pas une facture. `updated_at` y figure
     * parce qu'Eloquent l'écrit à chaque sauvegarde ; l'omettre ferait échouer le règlement
     * lui-même, c'est-à-dire l'opération qu'on veut précisément autoriser.
     */
    private const MODIFIABLES_APRES_EMISSION = [
        'montant_regle',
        'statut',
        'date_paiement',
        'updated_at',
    ];

    protected $fillable = [
        'structure_sanitaire_id',
        'reference',
        'periode_debut',
        'periode_fin',
        'montant_abonnement',
        'montant_commissions',
        'montant_total',
        'montant_regle',
        'devise',
        'statut',
        'date_emission',
        'date_echeance',
        'date_paiement',
    ];

    protected function casts(): array
    {
        return [
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'montant_abonnement' => 'integer',
            'montant_commissions' => 'integer',
            'montant_total' => 'integer',
            'montant_regle' => 'integer',
            'statut' => StatutFacturePartenaire::class,
            'date_emission' => 'date',
            'date_echeance' => 'date',
            'date_paiement' => 'date',
        ];
    }

    /**
     * Solde restant dû. DÉRIVÉ, jamais stocké — voir l'en-tête.
     *
     * Peut devenir négatif si un règlement excède le total : ce n'est pas une anomalie à masquer
     * par un `max(0, …)`, c'est un trop-perçu que l'imputation doit reporter sur la facture
     * suivante. Le taire ici le rendrait invisible là où il doit être traité.
     */
    public function getSoldeAttribute(): int
    {
        return (int) $this->montant_total - (int) $this->montant_regle;
    }

    public function getEstSoldeeAttribute(): bool
    {
        return $this->solde <= 0;
    }

    /** @return BelongsTo<StructureSanitaire, $this> */
    public function structureSanitaire(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class);
    }

    /** @return HasMany<ReglementFacturePartenaire, $this> */
    public function reglements(): HasMany
    {
        return $this->hasMany(ReglementFacturePartenaire::class);
    }

    /** @return HasMany<CommissionTransaction, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionTransaction::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $facture) {
            // On lit l'état AVANT modification, et en valeur BRUTE : `getOriginal()` applique les
            // casts, donc rendrait tantôt un enum tantôt une chaîne selon le chemin d'écriture.
            // Un garde-fou dont la comparaison dépend du chemin emprunté n'en est pas un.
            $statutInitial = $facture->getRawOriginal('statut');

            if ($statutInitial === StatutFacturePartenaire::PAYEE->value) {
                throw new RuntimeException(
                    'Une facture partenaire PAYEE ne se modifie plus. Une correction s\'écrit par '
                    .'une nouvelle pièce, jamais en réécrivant une facture soldée.'
                );
            }

            // BROUILLON : la facture se prépare, tout peut encore bouger. À partir de EMISE, elle
            // est opposable au partenaire — les montants sont figés.
            if ($statutInitial === null || $statutInitial === StatutFacturePartenaire::BROUILLON->value) {
                return;
            }

            $interdites = array_diff(array_keys($facture->getDirty()), self::MODIFIABLES_APRES_EMISSION);

            if ($interdites !== []) {
                throw new RuntimeException(
                    'Les montants d\'une facture partenaire sont figés dès son émission. Colonnes '
                    .'refusées : '.implode(', ', $interdites).'. Seules '
                    .implode(', ', self::MODIFIABLES_APRES_EMISSION).' peuvent encore changer.'
                );
            }
        });
    }
}
