package ci.masante.payment.service;

/**
 * Action que le FRONT doit accomplir après une opération carte — seule information d'interaction exposée
 * au client. Ne révèle JAMAIS le {@code StatutCarte} interne (interdit #8) : le front ne connaît que
 * « rien à faire / défi 3DS / redirection / refus », plus le {@code PaiementStatut} générique.
 */
public enum ActionClient {

    /** Rien à faire : l'opération est aboutie ou en attente côté serveur (webhook/job). */
    AUCUNE,
    /** Défi 3DS2 à exécuter (modalité tokenisée) : utiliser {@code challengeRef}. */
    DEFI_3DS,
    /** Redirection vers la page hébergée du PSP (modalité redirigée) : ouvrir {@code urlRedirection}. */
    REDIRECTION,
    /** Refus : présenter {@code codeRefusPublic} (motif générique, anti-fuite). */
    REFUSEE
}
