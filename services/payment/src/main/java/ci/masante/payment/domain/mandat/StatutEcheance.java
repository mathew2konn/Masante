package ci.masante.payment.domain.mandat;

/**
 * État d'une échéance de mandat (CDC_06 §5.4). {@code PLANIFIEE} → {@code PREAVIS} (préavis posé) →
 * {@code EXECUTEE} (débit MIT réussi) ou {@code ECHOUEE} (refus passerelle). {@code SAUTEE} = mandat
 * non actif au moment de l'échéance (ni débit ni préavis).
 */
public enum StatutEcheance {
    PLANIFIEE,
    PREAVIS,
    EXECUTEE,
    ECHOUEE,
    SAUTEE
}
