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
    REFUNDED;

    /**
     * Vrai si l'issue de la transaction est arrêtée — c'est le moment, et le seul, où un partenaire
     * a quelque chose à apprendre (lot 6, canal interne).
     *
     * <p>Il n'existe PAS d'état {@code EXPIRED} dans cette machine, contrairement à ce que
     * suggéreraient les sous-états d'un prestataire : une expiration se projette sur {@code FAILED}
     * (le détail « expiré » reste dans le sous-état backend-only, pour la réconciliation).</p>
     */
    public boolean estTerminal() {
        return switch (this) {
            case SUCCESS, FAILED, CANCELLED, REFUNDED -> true;
            case INITIATED, PENDING, PROCESSING -> false;
        };
    }
}
