package ci.masante.payment.service;

/**
 * Rôle applicatif insuffisant pour une action sensible (ex. changement de taux de commission réservé
 * à ADMIN_FINANCE) → 403. Le rôle vient de l'en-tête {@code X-Acteur-Role} posé par la passerelle
 * authentifiée (non usurpable) ; message générique (anti-fuite).
 */
public class RoleInsuffisantException extends RuntimeException {
    public RoleInsuffisantException() {
        super("Action non autorisée pour ce rôle.");
    }
}
