package ci.masante.payment.domain.notification;

/**
 * Types de notifications émises par le domaine paiement (CDC_06 §5.4). {@code PRELEVEMENT_IMMINENT} =
 * notification AVANT prélèvement (préavis) ; {@code PRELEVEMENT_ECHOUE} = échec d'un prélèvement.
 * Le contenu est une DONNÉE (jamais du métier codé) ; le canal réel est choisi par l'envoyeur.
 *
 * <p><b>Deux natures de destinataire, jamais mélangées</b> (lot 6, canal interne) : les types
 * ci-dessous s'adressent à un <b>humain</b> (SMS/push/email) et sont livrés par {@link
 * EnvoiNotification} ; {@link #PAIEMENT_NOTIFICATION_LARAVEL} s'adresse à un <b>système partenaire</b>
 * et est livré par {@link NotificationSysteme}. Faire passer les deux par le même adaptateur serait
 * une confusion de nature : « envoyer un SMS à un patient » et « notifier un serveur » n'ont ni le
 * même transport, ni la même authentification, ni le même sens en cas d'échec.</p>
 *
 * <p>Le drapeau vit ICI et non dans le relais : ajouter un futur type système ne demandera alors
 * aucune modification de {@code ServiceNotifications}.</p>
 */
public enum TypeNotification {
    PRELEVEMENT_IMMINENT,
    PRELEVEMENT_ECHOUE,
    // B1 (approfondissement fraude) : alerte de fraude IA routée vers le contrôleur plateforme ADMIN_FINANCE.
    FRAUDE_SUSPECTEE,
    // Lot 6 — notification SYSTÈME vers Laravel à chaque transition terminale d'un paiement.
    PAIEMENT_NOTIFICATION_LARAVEL;

    /** Vrai si ce type s'adresse à un système (appel signé), pas à une personne. */
    public boolean estSysteme() {
        return this == PAIEMENT_NOTIFICATION_LARAVEL;
    }
}
