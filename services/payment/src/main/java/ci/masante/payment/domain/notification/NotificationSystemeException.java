package ci.masante.payment.domain.notification;

/** Échec de livraison d'une notification système : le relais réessaiera selon sa politique existante. */
public class NotificationSystemeException extends RuntimeException {

    public NotificationSystemeException(String message) {
        super(message);
    }

    public NotificationSystemeException(String message, Throwable cause) {
        super(message, cause);
    }
}
