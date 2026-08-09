package ci.masante.payment.domain.notification;

/**
 * Port d'envoi de notification (CDC_06 §5.4). OCP : un canal réel (SMS/push/email) = une nouvelle
 * implémentation, jamais un {@code if canal==…}. Aujourd'hui seul un adaptateur SIMULÉ existe (FT5).
 */
public interface EnvoiNotification {

    /** Livre le message ; le résultat est décidé par l'envoyeur (jamais par l'appelant). */
    ResultatEnvoi envoyer(MessageNotification message);
}
