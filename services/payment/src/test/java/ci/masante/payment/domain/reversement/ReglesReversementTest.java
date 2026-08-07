package ci.masante.payment.domain.reversement;

import ci.masante.payment.domain.model.TypeLigneReversement;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/**
 * Règles PURES du calcul de reversement (P5.5a, CDC_06 §11) — frontière. Test unitaire pur, sans base
 * (G3). Prouve : commission arrondie plancher, I3 exact (Σ lignes = en-tête), report négatif → net 0 +
 * solde reporté, report antérieur repris, taux/montants invalides rejetés.
 */
class ReglesReversementTest {

    private static EncaissementImputable enc(long montant) {
        return new EncaissementImputable(UUID.randomUUID(), "FCT-001", Instant.parse("2026-01-15T10:00:00Z"), montant);
    }

    private static RemboursementImputable remb(long montant) {
        return new RemboursementImputable(UUID.randomUUID(), "RMB-001", Instant.parse("2026-01-20T10:00:00Z"), montant);
    }

    @Test
    @DisplayName("Commission arrondie PLANCHER (division entière), en faveur de l'établissement")
    void commissionPlancher() {
        // 100 000 × 250 bps / 10000 = 2 500 pile ; 33 333 × 250 / 10000 = 833,325 → 833.
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(100_000), enc(33_333)), List.of(), 250, 0);
        assertThat(r.montantBrutDu()).isEqualTo(133_333);
        assertThat(r.montantCommission()).isEqualTo(2_500 + 833);
        assertThat(r.montantNetAReverser()).isEqualTo(133_333 - 3_333);
        assertThat(r.soldeReporte()).isZero();
    }

    @Test
    @DisplayName("I3 exact : Σ des lignes = totaux de l'en-tête")
    void sommeLignesEgaleEntete() {
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(70_000), enc(30_000)), List.of(remb(5_000)), 250, 0);
        long sommeRegle = r.lignes().stream().mapToLong(LigneCalculeeReversement::montantRegleImpute).sum();
        long sommeCommission = r.lignes().stream().mapToLong(LigneCalculeeReversement::montantCommissionLigne).sum();
        long sommeRemb = r.lignes().stream().mapToLong(LigneCalculeeReversement::montantRembourseImpute).sum();
        assertThat(sommeRegle).isEqualTo(r.montantBrutDu());
        assertThat(sommeCommission).isEqualTo(r.montantCommission());
        assertThat(sommeRemb).isEqualTo(r.montantRembourse());
    }

    @Test
    @DisplayName("Équation garantie : net + soldeReporte = brut − commission − remb + report")
    void equationRespectee() {
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(50_000)), List.of(remb(2_000)), 250, -3_000);
        assertThat(r.montantNetAReverser() + r.soldeReporte())
                .isEqualTo(r.montantBrutDu() - r.montantCommission() - r.montantRembourse() + r.reportAnterieur());
    }

    @Test
    @DisplayName("Remboursements > encaissement net → net 0 et dette reportée (aucun décaissement)")
    void reportNegatif() {
        // brut 10 000, commission 250, remb 40 000 → net théorique = -30 250.
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(10_000)), List.of(remb(40_000)), 250, 0);
        assertThat(r.montantNetAReverser()).isZero();
        assertThat(r.soldeReporte()).isEqualTo(-30_250);
    }

    @Test
    @DisplayName("Report antérieur négatif repris : diminue le net à décaisser")
    void reportAnterieurRepris() {
        // brut 100 000, commission 2 500, report -30 250 → net = 67 250.
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(100_000)), List.of(), 250, -30_250);
        assertThat(r.montantNetAReverser()).isEqualTo(67_250);
        assertThat(r.soldeReporte()).isZero();
    }

    @Test
    @DisplayName("Report antérieur qui absorbe tout le net → report qui persiste")
    void reportAnterieurPersiste() {
        // brut 5 000, commission 125, report -10 000 → net théorique = -5 125.
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(5_000)), List.of(), 250, -10_000);
        assertThat(r.montantNetAReverser()).isZero();
        assertThat(r.soldeReporte()).isEqualTo(-5_125);
    }

    @Test
    @DisplayName("Deux natures de ligne : FACTURE commissionnée, REMBOURSEMENT sans commission")
    void naturesDeLigne() {
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(100_000)), List.of(remb(5_000)), 250, 0);
        LigneCalculeeReversement facture = r.lignes().stream()
                .filter(l -> l.type() == TypeLigneReversement.FACTURE).findFirst().orElseThrow();
        LigneCalculeeReversement remboursement = r.lignes().stream()
                .filter(l -> l.type() == TypeLigneReversement.REMBOURSEMENT).findFirst().orElseThrow();
        assertThat(facture.montantCommissionLigne()).isEqualTo(2_500);
        assertThat(facture.montantNetLigne()).isEqualTo(97_500);
        assertThat(remboursement.montantCommissionLigne()).isZero();
        assertThat(remboursement.montantNetLigne()).isEqualTo(-5_000);
    }

    @Test
    @DisplayName("Assiette vide → tout à zéro (aucun relevé fantôme requis)")
    void assietteVide() {
        ResultatReversement r = ReglesReversement.calculer(List.of(), List.of(), 250, 0);
        assertThat(r.montantBrutDu()).isZero();
        assertThat(r.montantNetAReverser()).isZero();
        assertThat(r.soldeReporte()).isZero();
        assertThat(r.lignes()).isEmpty();
    }

    @Test
    @DisplayName("Taux 0 % : net = brut (commission nulle)")
    void tauxZero() {
        ResultatReversement r = ReglesReversement.calculer(List.of(enc(100_000)), List.of(), 0, 0);
        assertThat(r.montantCommission()).isZero();
        assertThat(r.montantNetAReverser()).isEqualTo(100_000);
    }

    @Test
    @DisplayName("Taux hors bornes → rejet")
    void tauxHorsBornes() {
        assertThatThrownBy(() -> ReglesReversement.calculer(List.of(enc(1_000)), List.of(), 10_001, 0))
                .isInstanceOf(ReversementInvalideException.class);
        assertThatThrownBy(() -> ReglesReversement.calculer(List.of(enc(1_000)), List.of(), -1, 0))
                .isInstanceOf(ReversementInvalideException.class);
    }

    @Test
    @DisplayName("Report antérieur positif → rejet (ne peut être qu'une dette)")
    void reportPositifRejete() {
        assertThatThrownBy(() -> ReglesReversement.calculer(List.of(enc(1_000)), List.of(), 250, 1))
                .isInstanceOf(ReversementInvalideException.class);
    }
}
