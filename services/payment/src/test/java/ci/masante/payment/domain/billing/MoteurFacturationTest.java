package ci.masante.payment.domain.billing;

import ci.masante.payment.domain.coverage.TypePriseEnCharge;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.List;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Calcul de facture (CDC_06 §7) : HT, TVA (donnée), remises, prise en charge, reste à payer. */
class MoteurFacturationTest {

    private static EntreeFacturation facture(List<LigneEntree> lignes, long remiseGlobale,
                                             ParametresPriseEnCharge pec) {
        return new EntreeFacturation("CHU-COCODY", "patient-1", 2026, "XOF", lignes, remiseGlobale, pec);
    }

    @Test
    @DisplayName("Ligne simple sans TVA ni remise")
    void ligneSimple() {
        ResultatFacturation r = MoteurFacturation.calculer(
                facture(List.of(new LigneEntree("Consultation", 1, 20_000, 0, 0)), 0, null));

        assertThat(r.sousTotalHt()).isEqualTo(20_000);
        assertThat(r.totalTva()).isZero();
        assertThat(r.montantTtc()).isEqualTo(20_000);
        assertThat(r.resteAPayer()).isEqualTo(20_000);
    }

    @Test
    @DisplayName("TVA en donnée : 18 % de 10 000 = 1 800 → TTC 11 800")
    void tva() {
        ResultatFacturation r = MoteurFacturation.calculer(
                facture(List.of(new LigneEntree("Acte", 1, 10_000, 0, 18)), 0, null));

        assertThat(r.totalTva()).isEqualTo(1_800);
        assertThat(r.montantTtc()).isEqualTo(11_800);
    }

    @Test
    @DisplayName("Remise de ligne : HT = quantité×PU − remise")
    void remiseLigne() {
        ResultatFacturation r = MoteurFacturation.calculer(
                facture(List.of(new LigneEntree("Médicament", 2, 5_000, 2_000, 0)), 0, null));

        assertThat(r.sousTotalHt()).isEqualTo(8_000); // 2×5000 − 2000
        assertThat(r.totalRemises()).isEqualTo(2_000);
        assertThat(r.montantTtc()).isEqualTo(8_000);
    }

    @Test
    @DisplayName("Remise globale déduite du TTC")
    void remiseGlobale() {
        ResultatFacturation r = MoteurFacturation.calculer(
                facture(List.of(new LigneEntree("Acte", 1, 10_000, 0, 0)), 1_500, null));

        assertThat(r.montantTtc()).isEqualTo(8_500);
        assertThat(r.totalRemises()).isEqualTo(1_500);
    }

    @Test
    @DisplayName("Plusieurs lignes : sommes correctes")
    void plusieursLignes() {
        ResultatFacturation r = MoteurFacturation.calculer(facture(List.of(
                new LigneEntree("Consultation", 1, 15_000, 0, 0),
                new LigneEntree("Analyse", 2, 5_000, 0, 10)), 0, null));

        assertThat(r.sousTotalHt()).isEqualTo(25_000);      // 15000 + 10000
        assertThat(r.totalTva()).isEqualTo(1_000);          // 10 % de 10000
        assertThat(r.montantTtc()).isEqualTo(26_000);
        assertThat(r.lignes()).hasSize(2);
    }

    @Test
    @DisplayName("Prise en charge CNAM 70 % sur le TTC → reste à payer patient")
    void priseEnCharge() {
        ResultatFacturation r = MoteurFacturation.calculer(facture(
                List.of(new LigneEntree("Consultation", 1, 20_000, 0, 0)), 0,
                new ParametresPriseEnCharge(TypePriseEnCharge.CNAM, 70, null, false)));

        assertThat(r.montantCouvert()).isEqualTo(14_000);
        assertThat(r.resteAPayer()).isEqualTo(6_000);
        assertThat(r.couvertureType()).isEqualTo(TypePriseEnCharge.CNAM);
    }

    @Test
    @DisplayName("Invariant : montantCouvert + resteAPayer == montantTtc")
    void invariant() {
        ResultatFacturation r = MoteurFacturation.calculer(facture(
                List.of(new LigneEntree("Hospitalisation", 1, 250_000, 0, 0)), 0,
                new ParametresPriseEnCharge(TypePriseEnCharge.ASSURANCE, 80, 150_000L, false)));

        assertThat(r.montantCouvert() + r.resteAPayer()).isEqualTo(r.montantTtc());
        assertThat(r.montantCouvert()).isEqualTo(150_000); // plafonné
    }

    @Test
    @DisplayName("Aucune ligne → exception")
    void aucuneLigne() {
        assertThatThrownBy(() -> MoteurFacturation.calculer(facture(List.of(), 0, null)))
                .isInstanceOf(FacturationInvalideException.class);
    }

    @Test
    @DisplayName("Remise de ligne supérieure au montant → exception")
    void remiseTropGrande() {
        assertThatThrownBy(() -> MoteurFacturation.calculer(
                facture(List.of(new LigneEntree("Acte", 1, 5_000, 6_000, 0)), 0, null)))
                .isInstanceOf(FacturationInvalideException.class);
    }

    @Test
    @DisplayName("Quantité nulle → exception")
    void quantiteNulle() {
        assertThatThrownBy(() -> MoteurFacturation.calculer(
                facture(List.of(new LigneEntree("Acte", 0, 5_000, 0, 0)), 0, null)))
                .isInstanceOf(FacturationInvalideException.class);
    }
}
