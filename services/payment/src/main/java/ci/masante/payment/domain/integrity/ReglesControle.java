package ci.masante.payment.domain.integrity;

import ci.masante.payment.domain.model.FactureStatut;
import ci.masante.payment.domain.model.OwnerTypeWallet;

import java.util.EnumSet;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Optional;
import java.util.Set;
import java.util.UUID;

/**
 * Règles PURES du contrôle d'intégrité financière interne (P5.3b-4, CDC_06 §6.3). Aucune I/O : chaque
 * méthode reçoit un agrégat déjà calculé (borné au cut-off T par la requête) et décide s'il constitue
 * un écart. Frontière : tout le JUGEMENT est ici, jamais dans un contrôleur → testable unitairement.
 *
 * <h2>Monnaie & arrondi — invariant Σ = 0 EXACT</h2>
 * Le FCFA (XOF) n'a pas de sous-unité : tous les montants sont des ENTIERS de francs ({@code long}).
 * La double écriture porte sur ses DEUX jambes la même valeur (la contrepartie est la négation exacte),
 * donc la somme d'une opération est nulle par construction, indépendamment de tout arrondi. Le seul
 * arrondi du domaine vient des pourcentages (cashback = base × bps / 10000, arrondi PLANCHER dans
 * {@code ReglesCashback}) : la valeur arrondie créditée est EXACTEMENT celle portée par l'écriture de
 * contrepartie. Règle : le reliquat d'arrondi reste sur la jambe créditée ; aucune jambe ne recalcule
 * sa valeur autrement. {@link #operationDesequilibree} et {@link #grandLivreNonNul} vérifient l'invariant.
 *
 * <h2>Motifs de solde négatif AUTORISÉS — liste ÉNUMÉRÉE</h2>
 * Un invariant à exception est l'endroit exact où se cachent les bugs : la seule dette de solde tolérée
 * est ÉNUMÉRÉE ici — {@link OwnerTypeWallet#SYSTEME} (comptes techniques de contrepartie/émission,
 * négatifs par conception). Tout autre type (PATIENT, ETABLISSEMENT) à solde négatif = écart.
 */
public final class ReglesControle {

    /** Types de wallet dont un solde négatif est LÉGITIME (émission/contrepartie). */
    private static final Set<OwnerTypeWallet> DETTE_AUTORISEE = EnumSet.of(OwnerTypeWallet.SYSTEME);

    // ── Contrôle 1 — double écriture du grand livre wallet (§6.3) ──────────────────────────────

    /** Une opération DOIT avoir exactement 2 écritures dont la somme est nulle. */
    public Optional<Ecart> operationDesequilibree(UUID operationId, long nombreEcritures, long somme) {
        if (nombreEcritures == 2 && somme == 0) {
            return Optional.empty();
        }
        return Optional.of(new Ecart(TypeControle.DOUBLE_ECRITURE, TypeEcart.OPERATION_DESEQUILIBREE,
                Severite.CRITIQUE, "operation:" + operationId, 0L, somme,
                details("nombreEcritures", nombreEcritures, "attenduNombre", 2, "attenduSomme", 0)));
    }

    /** La somme de TOUTES les écritures du grand livre doit valoir 0. */
    public Optional<Ecart> grandLivreNonNul(long sommeGlobale) {
        if (sommeGlobale == 0) {
            return Optional.empty();
        }
        return Optional.of(new Ecart(TypeControle.DOUBLE_ECRITURE, TypeEcart.GRAND_LIVRE_NON_NUL,
                Severite.CRITIQUE, "grand-livre", 0L, sommeGlobale, details()));
    }

    /** Solde négatif toléré UNIQUEMENT pour les types énumérés dans {@link #DETTE_AUTORISEE}. */
    public Optional<Ecart> soldeNegatif(UUID walletId, OwnerTypeWallet ownerType, long solde) {
        if (solde >= 0 || DETTE_AUTORISEE.contains(ownerType)) {
            return Optional.empty();
        }
        return Optional.of(new Ecart(TypeControle.DOUBLE_ECRITURE, TypeEcart.SOLDE_NEGATIF_NON_AUTORISE,
                Severite.CRITIQUE, "wallet:" + walletId, 0L, solde,
                details("ownerType", ownerType.name())));
    }

    // ── Contrôle 2 — cohérence statut de facture ↔ règlements (§7.3) ───────────────────────────

    /**
     * Le statut de la facture doit être cohérent avec le cumul réglé ({@code montantRegle}) par rapport
     * au RESTE À PAYER (le dû patient après couverture CNAM/assurance), et non au TTC : une facture
     * couverte est PAYEE dès que son reste est soldé.
     */
    public Optional<Ecart> factureIncoherente(String numero, FactureStatut statut,
                                              long montantRegle, long resteAPayer) {
        boolean coherent = switch (statut) {
            case EMISE -> montantRegle == 0;
            case PARTIELLEMENT_PAYEE -> montantRegle > 0 && montantRegle < resteAPayer;
            case PAYEE -> montantRegle >= resteAPayer;
            case ANNULEE, REMPLACEE -> true; // hors périmètre de règlement
        };
        if (coherent) {
            return Optional.empty();
        }
        return Optional.of(new Ecart(TypeControle.PAIEMENT_FACTURE, TypeEcart.FACTURE_STATUT_INCOHERENT,
                Severite.MAJEUR, "facture:" + numero, resteAPayer, montantRegle,
                details("statut", statut.name())));
    }

    /**
     * Cross-grand-livre DIRECTIONNEL : la somme des paiements PASSERELLE (table {@code payments})
     * SUCCESS d'une facture ne peut PAS excéder son montant réglé. On ne vérifie pas l'égalité car un
     * règlement depuis le WALLET ne crée pas de ligne {@code payments} — il gonfle légitimement
     * {@code montantRegle} sans somme passerelle. Seul le dépassement (encaissement non imputé) est
     * anormal.
     */
    public Optional<Ecart> encaissementNonRepercute(String numero, long montantRegle,
                                                    long sommePasserelleSuccess) {
        if (sommePasserelleSuccess <= montantRegle) {
            return Optional.empty();
        }
        return Optional.of(new Ecart(TypeControle.PAIEMENT_FACTURE, TypeEcart.ENCAISSEMENT_NON_REPERCUTE,
                Severite.CRITIQUE, "facture:" + numero, montantRegle, sommePasserelleSuccess, details()));
    }

    // ── Contrôle 3 — cohérence des campagnes de cashback (§6.1/§6.2) ───────────────────────────

    /** Cashback net consommé (crédits − clawbacks) ≤ budget de la campagne (budget null = illimité). */
    public Optional<Ecart> cashbackBudgetDepasse(String code, long consomme, Long budgetTotal) {
        if (budgetTotal == null || consomme <= budgetTotal) {
            return Optional.empty();
        }
        return Optional.of(new Ecart(TypeControle.CASHBACK, TypeEcart.CASHBACK_BUDGET_DEPASSE,
                Severite.MAJEUR, "campagne:" + code, budgetTotal, consomme, details()));
    }

    /** La reprise (clawback) d'une op source ne peut pas excéder le cashback d'origine (Σ ≤ origine). */
    public Optional<Ecart> clawbackSuperieurOrigine(UUID operationSourceId, long cashback, long clawback) {
        if (clawback <= cashback) {
            return Optional.empty();
        }
        return Optional.of(new Ecart(TypeControle.CASHBACK, TypeEcart.CLAWBACK_SUPERIEUR_ORIGINE,
                Severite.CRITIQUE, "source:" + operationSourceId, cashback, clawback, details()));
    }

    /** Petite fabrique de map d'explication (évite les pièges de généricité de {@code Map.of}). */
    private static Map<String, Object> details(Object... kv) {
        Map<String, Object> m = new LinkedHashMap<>();
        for (int i = 0; i + 1 < kv.length; i += 2) {
            m.put(String.valueOf(kv[i]), kv[i + 1]);
        }
        return m;
    }
}
