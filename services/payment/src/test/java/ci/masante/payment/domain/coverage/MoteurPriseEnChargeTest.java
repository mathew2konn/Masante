package ci.masante.payment.domain.coverage;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Vecteurs imposés par le CDC_06 §8 + cas limites (plafond, exclusion, bornes). */
class MoteurPriseEnChargeTest {

    @Test
    @DisplayName("CDC §8.1 : consultation 20 000 @ 70 % → CNAM 14 000, patient 6 000")
    void cnamConsultation() {
        ResultatCouverture r = MoteurPriseEnCharge.calculer(
                new RequeteCouverture(20_000, TypePriseEnCharge.CNAM, 70, null, false));

        assertThat(r.montantCouvert()).isEqualTo(14_000);
        assertThat(r.resteACharge()).isEqualTo(6_000);
        assertThat(r.ticketModerateur()).isEqualTo(6_000);
        assertThat(r.plafondApplique()).isFalse();
    }

    @Test
    @DisplayName("CDC §8.2 : hospitalisation 250 000 @ 80 % → assurance 200 000, patient 50 000")
    void assuranceHospitalisation() {
        ResultatCouverture r = MoteurPriseEnCharge.calculer(
                new RequeteCouverture(250_000, TypePriseEnCharge.ASSURANCE, 80, null, false));

        assertThat(r.montantCouvert()).isEqualTo(200_000);
        assertThat(r.resteACharge()).isEqualTo(50_000);
    }

    @Test
    @DisplayName("Invariant : montantCouvert + resteACharge == montantTotal")
    void invariant() {
        ResultatCouverture r = MoteurPriseEnCharge.calculer(
                new RequeteCouverture(33_333, TypePriseEnCharge.CNAM, 65, null, false));

        assertThat(r.montantCouvert() + r.resteACharge()).isEqualTo(33_333);
    }

    @Test
    @DisplayName("Plafond : la couverture est bornée et le patient paie la différence")
    void plafond() {
        // 80 % de 250 000 = 200 000, mais plafond 150 000 → couvre 150 000, reste 100 000.
        ResultatCouverture r = MoteurPriseEnCharge.calculer(
                new RequeteCouverture(250_000, TypePriseEnCharge.ASSURANCE, 80, 150_000L, false));

        assertThat(r.montantCouvert()).isEqualTo(150_000);
        assertThat(r.resteACharge()).isEqualTo(100_000);
        assertThat(r.plafondApplique()).isTrue();
    }

    @Test
    @DisplayName("Acte exclu : aucune couverture, le patient paie tout")
    void exclu() {
        ResultatCouverture r = MoteurPriseEnCharge.calculer(
                new RequeteCouverture(20_000, TypePriseEnCharge.ASSURANCE, 80, null, true));

        assertThat(r.montantCouvert()).isZero();
        assertThat(r.resteACharge()).isEqualTo(20_000);
        assertThat(r.exclu()).isTrue();
    }

    @Test
    @DisplayName("Arrondi FCFA au plus proche (HALF_UP) sur taux non entier de résultat")
    void arrondi() {
        // 33 % de 10 000 = 3 300 exact ; 33 % de 999 = 329,67 → 330.
        assertThat(MoteurPriseEnCharge.calculer(
                new RequeteCouverture(999, TypePriseEnCharge.CNAM, 33, null, false)).montantCouvert())
                .isEqualTo(330);
    }

    @Test
    @DisplayName("Taux hors bornes → exception")
    void tauxInvalide() {
        assertThatThrownBy(() -> MoteurPriseEnCharge.calculer(
                new RequeteCouverture(20_000, TypePriseEnCharge.CNAM, 120, null, false)))
                .isInstanceOf(CouvertureInvalideException.class);
    }

    @Test
    @DisplayName("Montant négatif ou nul → exception")
    void montantInvalide() {
        assertThatThrownBy(() -> MoteurPriseEnCharge.calculer(
                new RequeteCouverture(0, TypePriseEnCharge.CNAM, 70, null, false)))
                .isInstanceOf(CouvertureInvalideException.class);
    }
}
