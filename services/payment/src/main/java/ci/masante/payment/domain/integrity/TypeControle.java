package ci.masante.payment.domain.integrity;

/**
 * Les trois familles de contrôle d'intégrité financière interne (P5.3b-4, CDC_06 §6.3).
 * Ce n'est PAS un rapprochement (une seule source : notre base). Voir ADR-014.
 */
public enum TypeControle {
    /** Grand livre wallet : double écriture (§6.3). */
    DOUBLE_ECRITURE,
    /** Cohérence statut de facture ↔ règlements (§7.3). */
    PAIEMENT_FACTURE,
    /** Cohérence des campagnes de cashback (budget, clawback) (§6.1/§6.2). */
    CASHBACK
}
