package ci.masante.payment.domain.model;

/** Canal de versement d'un reversement (CDC_06 §11). */
public enum TypeDestination {
    MOBILE_MONEY,
    VIREMENT_BANCAIRE
}
