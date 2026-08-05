package ci.masante.payment.domain.integrity;

/** Sévérité d'un écart : CRITIQUE (invariant financier rompu) < MAJEUR (incohérence à investiguer). */
public enum Severite {
    CRITIQUE,
    MAJEUR
}
