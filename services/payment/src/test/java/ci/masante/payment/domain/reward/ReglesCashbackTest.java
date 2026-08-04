package ci.masante.payment.domain.reward;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Règles pures du cashback (CDC_06 §6.2) — frontière. Test pur, exécuté au build. */
class ReglesCashbackTest {

    @Test
    @DisplayName("Cashback = base × bps / 10000, arrondi plancher")
    void calcul() {
        assertThat(ReglesCashback.calculer(10_000, 500, 0)).isEqualTo(500);   // 5 %
        assertThat(ReglesCashback.calculer(999, 500, 0)).isEqualTo(49);       // 49,95 → 49 (plancher)
        assertThat(ReglesCashback.calculer(0, 500, 0)).isZero();
        assertThat(ReglesCashback.calculer(10_000, 0, 0)).isZero();           // taux nul
    }

    @Test
    @DisplayName("Plafond par opération respecté")
    void plafond() {
        assertThat(ReglesCashback.calculer(100_000, 500, 300)).isEqualTo(300); // 5000 plafonné à 300
    }

    @Test
    @DisplayName("Base négative → refus (#7)")
    void baseNegative() {
        assertThatThrownBy(() -> ReglesCashback.calculer(-1, 500, 0))
                .isInstanceOf(CashbackInvalideException.class);
    }

    @Test
    @DisplayName("Clawback : remboursement total soldant reprend tout le cashback")
    void clawbackTotal() {
        assertThat(ReglesCashback.calculerClawback(500, 0, 10_000, 10_000, true)).isEqualTo(500);
    }

    @Test
    @DisplayName("Clawback partiel proportionnel (plancher)")
    void clawbackPartiel() {
        // rembourse 4000 sur 10000 → 500 × 4000/10000 = 200
        assertThat(ReglesCashback.calculerClawback(500, 0, 4_000, 10_000, false)).isEqualTo(200);
    }

    @Test
    @DisplayName("Clawbacks cumulés plafonnés ; le remboursement soldant reprend le reliquat exact")
    void clawbackReliquat() {
        // déjà repris 200 ; remboursement soldant (2999) → reliquat exact 300, pas floor(500×2999/10000)=149
        assertThat(ReglesCashback.calculerClawback(500, 200, 2_999, 10_000, true)).isEqualTo(300);
        // jamais au-delà du cashback d'origine
        assertThat(ReglesCashback.calculerClawback(500, 500, 5_000, 10_000, false)).isZero();
    }

    @Test
    @DisplayName("Clawback sur remboursement nul ou cashback nul → 0")
    void clawbackNul() {
        assertThat(ReglesCashback.calculerClawback(500, 0, 0, 10_000, false)).isZero();
        assertThat(ReglesCashback.calculerClawback(0, 0, 5_000, 10_000, false)).isZero();
    }
}
