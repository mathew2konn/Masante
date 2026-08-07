package ci.masante.payment.service;

/**
 * Webhook rejeté (signature invalide, hors fenêtre de fraîcheur, ou corps illisible) → HTTP 401.
 * Message TOUJOURS générique (anti-fuite §7.3) : ne jamais révéler LEQUEL des contrôles a échoué.
 */
public class WebhookInvalideException extends RuntimeException {

    public WebhookInvalideException() {
        super("Webhook rejeté.");
    }
}
