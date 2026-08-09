package ci.masante.payment.domain.notification;

/** État d'une ligne de l'outbox de notifications. {@code ENVOYEE}/{@code ECHOUEE} sont terminaux. */
public enum StatutNotification {
    EN_ATTENTE,
    ENVOYEE,
    ECHOUEE
}
