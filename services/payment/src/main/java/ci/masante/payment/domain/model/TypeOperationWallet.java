package ci.masante.payment.domain.model;

/** Opérations du grand livre (CDC_06 §6.2). Chacune produit deux écritures (double écriture). */
public enum TypeOperationWallet {
    CREDIT,
    DEBIT,
    TRANSFERT,
    PAIEMENT_FACTURE,
    CASHBACK,
    BONUS,
    /** Reprise (clawback) d'un cashback quand l'opération source est remboursée (§6.2, réversibilité). */
    CASHBACK_ANNULATION
}
