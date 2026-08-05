package ci.masante.payment.domain.integrity;

/**
 * Taxonomie des écarts détectés par le contrôle d'intégrité (P5.3b-4). DÉTECTION SEULE : ces types
 * ne déclenchent jamais de correction automatique (CDC_06 §11 — « écarts signalés, jamais corrigés »).
 *
 * <p>Cette taxonomie est INTERNE au microservice pour l'instant. Elle sera promue dans
 * {@code @masante/shared} (source unique) quand l'écran d'administration web la consommera, et
 * étendue avec les types de rapprochement à deux sources de S11 (voir ADR-014).</p>
 */
public enum TypeEcart {
    /** Une opération n'a pas exactement DEUX écritures de somme nulle (double écriture rompue). */
    OPERATION_DESEQUILIBREE,
    /** Σ de toutes les écritures du grand livre ≠ 0 (l'invariant global est cassé). */
    GRAND_LIVRE_NON_NUL,
    /** Solde < 0 sur un wallet dont le type n'autorise PAS la dette (motifs autorisés énumérés). */
    SOLDE_NEGATIF_NON_AUTORISE,
    /** Statut de facture incohérent avec le montant réglé (ex. PAYEE mais reste dû non couvert). */
    FACTURE_STATUT_INCOHERENT,
    /** Σ des paiements passerelle SUCCESS d'une facture > son montant réglé (encaissement non imputé). */
    ENCAISSEMENT_NON_REPERCUTE,
    /** Cashback net consommé par une campagne > son budget (anti-siphon budget). */
    CASHBACK_BUDGET_DEPASSE,
    /** Σ clawback > cashback d'origine pour une opération source (réversibilité rompue). */
    CLAWBACK_SUPERIEUR_ORIGINE
}
