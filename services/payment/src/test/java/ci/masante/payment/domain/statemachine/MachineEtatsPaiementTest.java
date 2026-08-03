package ci.masante.payment.domain.statemachine;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static ci.masante.payment.domain.model.PaiementStatut.CANCELLED;
import static ci.masante.payment.domain.model.PaiementStatut.FAILED;
import static ci.masante.payment.domain.model.PaiementStatut.INITIATED;
import static ci.masante.payment.domain.model.PaiementStatut.PENDING;
import static ci.masante.payment.domain.model.PaiementStatut.PROCESSING;
import static ci.masante.payment.domain.model.PaiementStatut.REFUNDED;
import static ci.masante.payment.domain.model.PaiementStatut.SUCCESS;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Machine à états stricte (CDC_06 §4.2) : transitions valides ET invalides. */
class MachineEtatsPaiementTest {

    @Test
    @DisplayName("Chemin nominal INITIATED → PENDING → PROCESSING → SUCCESS → REFUNDED")
    void cheminNominal() {
        assertThatCode(() -> {
            MachineEtatsPaiement.verifier(INITIATED, PENDING);
            MachineEtatsPaiement.verifier(PENDING, PROCESSING);
            MachineEtatsPaiement.verifier(PROCESSING, SUCCESS);
            MachineEtatsPaiement.verifier(SUCCESS, REFUNDED);
        }).doesNotThrowAnyException();
    }

    @Test
    @DisplayName("Échecs et annulations autorisés depuis les états non finaux")
    void echecsEtAnnulations() {
        assertThat(MachineEtatsPaiement.estAutorisee(INITIATED, FAILED)).isTrue();
        assertThat(MachineEtatsPaiement.estAutorisee(PENDING, CANCELLED)).isTrue();
        assertThat(MachineEtatsPaiement.estAutorisee(PROCESSING, FAILED)).isTrue();
    }

    @Test
    @DisplayName("Transitions interdites → TransitionInvalideException")
    void transitionsInterdites() {
        assertThatThrownBy(() -> MachineEtatsPaiement.verifier(INITIATED, SUCCESS))
                .isInstanceOf(TransitionInvalideException.class);
        assertThatThrownBy(() -> MachineEtatsPaiement.verifier(SUCCESS, PENDING))
                .isInstanceOf(TransitionInvalideException.class);
        assertThatThrownBy(() -> MachineEtatsPaiement.verifier(FAILED, SUCCESS))
                .isInstanceOf(TransitionInvalideException.class);
    }

    @Test
    @DisplayName("On ne rembourse que depuis SUCCESS")
    void remboursementSeulementDepuisSuccess() {
        assertThat(MachineEtatsPaiement.estAutorisee(SUCCESS, REFUNDED)).isTrue();
        assertThat(MachineEtatsPaiement.estAutorisee(PROCESSING, REFUNDED)).isFalse();
    }

    @Test
    @DisplayName("États terminaux : aucune transition sortante")
    void terminaux() {
        assertThat(MachineEtatsPaiement.estTerminal(FAILED)).isTrue();
        assertThat(MachineEtatsPaiement.estTerminal(CANCELLED)).isTrue();
        assertThat(MachineEtatsPaiement.estTerminal(REFUNDED)).isTrue();
        assertThat(MachineEtatsPaiement.estTerminal(INITIATED)).isFalse();
    }
}
