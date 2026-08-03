package ci.masante.payment.domain.model;

/**
 * États d'une facture (CDC_06 §7). <b>SOURCE UNIQUE</b> : réplique l'enum {@code FactureStatut} de
 * {@code @masante/shared}. Fourni par le backend, jamais déduit au front.
 *
 * <pre>EMISE → PARTIELLEMENT_PAYEE → PAYEE   ·   (EMISE) → ANNULEE</pre>
 */
public enum FactureStatut {
    EMISE,
    PARTIELLEMENT_PAYEE,
    PAYEE,
    ANNULEE,
    REMPLACEE
}
