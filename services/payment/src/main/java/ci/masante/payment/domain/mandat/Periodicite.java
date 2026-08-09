package ci.masante.payment.domain.mandat;

import java.time.LocalDate;

/**
 * Périodicité d'un mandat récurrent (CDC_06 §5.4 : échéancier). L'intervalle est une DONNÉE ; le calcul
 * de la prochaine échéance est du BACKEND (frontière §0.1). Classe pure, sans I/O.
 */
public enum Periodicite {
    HEBDOMADAIRE,
    MENSUEL,
    TRIMESTRIEL,
    ANNUEL;

    /** Prochaine date d'échéance à partir d'une date d'ancrage (jamais dans le front). */
    public LocalDate prochaine(LocalDate depuis) {
        return switch (this) {
            case HEBDOMADAIRE -> depuis.plusWeeks(1);
            case MENSUEL -> depuis.plusMonths(1);
            case TRIMESTRIEL -> depuis.plusMonths(3);
            case ANNUEL -> depuis.plusYears(1);
        };
    }
}
