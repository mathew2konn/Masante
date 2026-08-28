package ci.masante.payment.domain.notification;

/**
 * Port de notification SYSTÈME : livrer une charge utile à un service partenaire (aujourd'hui le
 * backend Laravel de MASANTÉ), par un appel authentifié — jamais un SMS.
 *
 * <p>Port DISTINCT d'{@link EnvoiNotification} à dessein (lot 6). Un envoi humain a un destinataire,
 * un canal souhaité, et son échec se rattrape par un autre canal. Un appel système a une URL, une
 * signature, et son échec se rattrape par une nouvelle tentative. Les fondre aurait fait porter au
 * même adaptateur deux responsabilités dont rien ne garantit qu'elles évoluent ensemble.</p>
 *
 * <p>Un échec de livraison lève {@link NotificationSystemeException} : le relais existant applique
 * alors SA politique de rejeu (verrou pessimiste + garde d'état + compteur de tentatives). Aucune
 * politique nouvelle n'est inventée ici.</p>
 */
public interface NotificationSysteme {

    /** Nom du canal consigné dans l'outbox lorsqu'une livraison système aboutit. */
    String CANAL = "SYSTEME_HTTP";

    /**
     * Livre la charge utile (JSON déjà sérialisé) au service partenaire.
     *
     * @throws NotificationSystemeException si la livraison n'a pas abouti
     */
    void livrer(String chargeJson);
}
