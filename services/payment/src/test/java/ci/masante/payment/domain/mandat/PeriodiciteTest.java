package ci.masante.payment.domain.mandat;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.time.LocalDate;

import static org.assertj.core.api.Assertions.assertThat;

/** Calcul de la prochaine échéance (P5.4b) — pur, backend (frontière §0.1). */
class PeriodiciteTest {

    private static final LocalDate ANCRE = LocalDate.of(2026, 1, 31);

    @Test
    @DisplayName("Chaque périodicité calcule la bonne prochaine échéance")
    void prochaine() {
        assertThat(Periodicite.HEBDOMADAIRE.prochaine(ANCRE)).isEqualTo(LocalDate.of(2026, 2, 7));
        assertThat(Periodicite.MENSUEL.prochaine(ANCRE)).isEqualTo(LocalDate.of(2026, 2, 28)); // fin de mois géré
        assertThat(Periodicite.TRIMESTRIEL.prochaine(ANCRE)).isEqualTo(LocalDate.of(2026, 4, 30));
        assertThat(Periodicite.ANNUEL.prochaine(ANCRE)).isEqualTo(LocalDate.of(2027, 1, 31));
    }

    @Test
    @DisplayName("Chaînage : appliquer la périodicité en série avance correctement")
    void chainage() {
        LocalDate d = LocalDate.of(2026, 3, 15);
        d = Periodicite.MENSUEL.prochaine(d);
        assertThat(d).isEqualTo(LocalDate.of(2026, 4, 15));
        d = Periodicite.MENSUEL.prochaine(d);
        assertThat(d).isEqualTo(LocalDate.of(2026, 5, 15));
    }
}
