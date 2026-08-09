package ci.masante.payment.domain.mandat;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Machine à états mandat (P5.4b) — pure : transitions légales/illégales + annulation à tout moment. */
class MachineEtatsMandatTest {

    @Test
    @DisplayName("ACTIF peut être suspendu, annulé, expiré")
    void depuisActif() {
        assertThat(MachineEtatsMandat.transition(MandatStatut.ACTIF, ActionMandat.SUSPENDRE))
                .isEqualTo(MandatStatut.SUSPENDU);
        assertThat(MachineEtatsMandat.transition(MandatStatut.ACTIF, ActionMandat.ANNULER))
                .isEqualTo(MandatStatut.ANNULE);
        assertThat(MachineEtatsMandat.transition(MandatStatut.ACTIF, ActionMandat.EXPIRER))
                .isEqualTo(MandatStatut.EXPIRE);
    }

    @Test
    @DisplayName("SUSPENDU peut être repris ou annulé")
    void depuisSuspendu() {
        assertThat(MachineEtatsMandat.transition(MandatStatut.SUSPENDU, ActionMandat.REPRENDRE))
                .isEqualTo(MandatStatut.ACTIF);
        assertThat(MachineEtatsMandat.transition(MandatStatut.SUSPENDU, ActionMandat.ANNULER))
                .isEqualTo(MandatStatut.ANNULE);
    }

    @Test
    @DisplayName("Annulation possible à tout moment (ACTIF et SUSPENDU) — §5.4")
    void annulationAToutMoment() {
        assertThat(MachineEtatsMandat.estAutorisee(MandatStatut.ACTIF, ActionMandat.ANNULER)).isTrue();
        assertThat(MachineEtatsMandat.estAutorisee(MandatStatut.SUSPENDU, ActionMandat.ANNULER)).isTrue();
    }

    @Test
    @DisplayName("Les états terminaux n'admettent aucune action")
    void terminaux() {
        assertThat(MachineEtatsMandat.estAutorisee(MandatStatut.ANNULE, ActionMandat.REPRENDRE)).isFalse();
        assertThat(MachineEtatsMandat.estAutorisee(MandatStatut.EXPIRE, ActionMandat.SUSPENDRE)).isFalse();
        assertThatThrownBy(() -> MachineEtatsMandat.transition(MandatStatut.ANNULE, ActionMandat.REPRENDRE))
                .isInstanceOf(TransitionMandatIllegale.class);
    }

    @Test
    @DisplayName("Reprendre un mandat actif est illégal")
    void reprendreActifIllegal() {
        assertThatThrownBy(() -> MachineEtatsMandat.transition(MandatStatut.ACTIF, ActionMandat.REPRENDRE))
                .isInstanceOf(TransitionMandatIllegale.class);
    }
}
