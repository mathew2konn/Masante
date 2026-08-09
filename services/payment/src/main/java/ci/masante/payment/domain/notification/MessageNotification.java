package ci.masante.payment.domain.notification;

/**
 * Message à livrer par un {@link EnvoiNotification}. {@code chargeJson} = contenu (JSON) déjà sérialisé
 * (montant, date d'échéance, libellé…). Le canal souhaité est indicatif ; l'envoyeur choisit le canal réel.
 */
public record MessageNotification(
        TypeNotification type,
        String destinataireRef,
        String canalSouhaite,
        String chargeJson
) {
}
