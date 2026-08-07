package ci.masante.payment.domain.carte;

/**
 * Issue AUTORITATIVE renvoyée par la passerelle (via {@code recupererStatut} ou webhook) — jamais
 * déclarée par le client (décision figée §1.2). Le service la traduit en événement de la machine carte.
 */
public enum IssuePasserelle {
    /** Toujours en attente d'interaction porteur (redirection non revenue, défi non complété). */
    EN_ATTENTE,
    /** Authentifiée + autorisée : prête à être capturée. */
    AUTORISE,
    /** Déjà capturée côté passerelle (auto-capture). */
    CAPTURE,
    /** Refus (authentification ou autorisation). */
    REFUSE,
    /** Délai dépassé côté passerelle. */
    EXPIRE
}
