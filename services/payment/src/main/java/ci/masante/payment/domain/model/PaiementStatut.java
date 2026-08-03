package ci.masante.payment.domain.model;

/**
 * États d'une transaction de paiement — machine à états stricte (CDC_06 §4.2).
 *
 * <p><b>SOURCE UNIQUE</b> : ces valeurs répliquent à l'identique l'enum {@code PaiementStatut}
 * de {@code @masante/shared} (packages/shared/src/enums). Le front les affiche, ne les déduit
 * jamais. Toute évolution se fait des deux côtés simultanément.</p>
 *
 * <pre>
 * INITIATED → PENDING → PROCESSING → SUCCESS
 *                                 ↘ FAILED
 *                                 ↘ CANCELLED
 * SUCCESS → REFUNDED
 * </pre>
 */
public enum PaiementStatut {
    INITIATED,
    PENDING,
    PROCESSING,
    SUCCESS,
    FAILED,
    CANCELLED,
    REFUNDED
}
