package ci.masante.payment.domain.mandat;

/**
 * Actions qui font évoluer l'état d'un mandat (CDC_06 §5.4). {@code ANNULER} est possible à tout moment
 * (exigence §5.4). {@code EXPIRER} est déclenché par l'atteinte de la date de fin (job).
 */
public enum ActionMandat {
    SUSPENDRE,
    REPRENDRE,
    ANNULER,
    EXPIRER
}
