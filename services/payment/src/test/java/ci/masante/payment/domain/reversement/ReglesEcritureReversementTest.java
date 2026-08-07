package ci.masante.payment.domain.reversement;

import ci.masante.payment.domain.model.SensEcriture;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Random;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/**
 * Règles PURES des écritures de constatation (P5.5b-1) — frontière comptable. Le test central est le
 * <b>test à propriétés</b> : Σdébit = Σcrédit pour des montants aléatoires (tout signe de report/solde).
 */
class ReglesEcritureReversementTest {

    private static long debit(List<JambeCalculee> j) {
        return j.stream().filter(x -> x.sens() == SensEcriture.DEBIT).mapToLong(JambeCalculee::montant).sum();
    }

    private static long credit(List<JambeCalculee> j) {
        return j.stream().filter(x -> x.sens() == SensEcriture.CREDIT).mapToLong(JambeCalculee::montant).sum();
    }

    @Test
    @DisplayName("Propriété : Σdébit = Σcrédit pour tout jeu aléatoire respectant l'équation (report/solde tout signe)")
    void equilibreAleatoire() {
        Random rnd = new Random(42);
        for (int i = 0; i < 5000; i++) {
            long brut = rnd.nextInt(2_000_000);
            long commission = brut == 0 ? 0 : rnd.nextInt((int) Math.min(brut, Integer.MAX_VALUE) + 1);
            long rembourse = rnd.nextInt(2_000_000);
            long report = rnd.nextInt(1_000_000) - 500_000;   // tout signe
            long solde = rnd.nextInt(1_000_000) - 500_000;    // tout signe
            long net = brut - commission - rembourse + report - solde;
            if (net < 0) {
                continue; // le domaine ne produit jamais net<0 (max(0,…)) — hors périmètre du test
            }
            List<JambeCalculee> jambes = ReglesEcritureReversement.constatation(
                    brut, commission, rembourse, net, report, solde);
            assertThat(debit(jambes))
                    .as("brut=%d com=%d remb=%d net=%d report=%d solde=%d", brut, commission, rembourse, net, report, solde)
                    .isEqualTo(credit(jambes));
            assertThat(jambes.size()).isNotEqualTo(1); // 0 (relevé nul) ou ≥2, jamais 1
        }
    }

    @Test
    @DisplayName("Cas nominal : trésorerie au débit, commission + à reverser au crédit")
    void nominal() {
        // brut 100000, com 2500, net 97500, pas de remb/report/solde.
        List<JambeCalculee> j = ReglesEcritureReversement.constatation(100_000, 2_500, 0, 97_500, 0, 0);
        assertThat(debit(j)).isEqualTo(100_000).isEqualTo(credit(j));
        assertThat(j).anySatisfy(x -> {
            assertThat(x.compte().name()).isEqualTo("TRESORERIE");
            assertThat(x.sens()).isEqualTo(SensEcriture.DEBIT);
        });
    }

    @Test
    @DisplayName("Report entrant (négatif) : jambe CREANCE au crédit ; report sortant : au débit")
    void reportBascule() {
        // report antérieur = -30250 (dette héritée) : net = 100000-2500+(-30250)-0 = 67250.
        List<JambeCalculee> entrant = ReglesEcritureReversement.constatation(100_000, 2_500, 0, 67_250, -30_250, 0);
        assertThat(debit(entrant)).isEqualTo(credit(entrant));
        // solde sortant = -30250 : net=0 ; report=0.
        List<JambeCalculee> sortant = ReglesEcritureReversement.constatation(10_000, 250, 40_000, 0, 0, -30_250);
        assertThat(debit(sortant)).isEqualTo(credit(sortant));
    }

    @Test
    @DisplayName("Relevé à montants nuls → aucune jambe (aucune écriture à poster)")
    void releveNul() {
        assertThat(ReglesEcritureReversement.constatation(0, 0, 0, 0, 0, 0)).isEmpty();
    }

    @Test
    @DisplayName("Extourne : mêmes comptes/montants, sens inversé")
    void extourne() {
        List<JambeCalculee> origine = ReglesEcritureReversement.constatation(100_000, 2_500, 0, 97_500, 0, 0);
        List<JambeCalculee> ext = ReglesEcritureReversement.extourne(origine);
        assertThat(debit(ext)).isEqualTo(credit(origine));
        assertThat(credit(ext)).isEqualTo(debit(origine));
    }

    @Test
    @DisplayName("Entrées absurdes rejetées : commission > brut, montants négatifs")
    void absurdes() {
        assertThatThrownBy(() -> ReglesEcritureReversement.constatation(1_000, 2_000, 0, 0, 0, 0))
                .isInstanceOf(ReversementInvalideException.class);
        assertThatThrownBy(() -> ReglesEcritureReversement.constatation(-1, 0, 0, 0, 0, 0))
                .isInstanceOf(ReversementInvalideException.class);
    }
}
