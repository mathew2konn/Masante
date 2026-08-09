package ci.masante.payment.domain.notification;

/**
 * Types de notifications émises par le domaine paiement (CDC_06 §5.4). {@code PRELEVEMENT_IMMINENT} =
 * notification AVANT prélèvement (préavis) ; {@code PRELEVEMENT_ECHOUE} = échec d'un prélèvement.
 * Le contenu est une DONNÉE (jamais du métier codé) ; le canal réel est choisi par l'envoyeur.
 */
public enum TypeNotification {
    PRELEVEMENT_IMMINENT,
    PRELEVEMENT_ECHOUE,
    // B1 (approfondissement fraude) : alerte de fraude IA routée vers le contrôleur plateforme ADMIN_FINANCE.
    FRAUDE_SUSPECTEE
}
