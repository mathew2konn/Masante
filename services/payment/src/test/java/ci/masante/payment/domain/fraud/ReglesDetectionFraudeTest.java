package ci.masante.payment.domain.fraud;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;

/** Moteur de détection de fraude par règles (CDC_06 §6.4) — frontière. Test pur, exécuté au build. */
class ReglesDetectionFraudeTest {

    // vélocité≥3 → +50 ; cumul+montant>50000 → +30 ; échecsPin≥5 → +30. Seuils 30/50/80.
    private static final ParametresFraude P =
            new ParametresFraude(3, 50_000, 5, 50, 30, 30, 30, 50, 80);

    private static ResultatFraude evaluer(int nbOps, long cumule, int echecsPin, long montant) {
        return ReglesDetectionFraude.evaluer(new SignauxFraude(nbOps, cumule, echecsPin, montant), P);
    }

    @Test
    @DisplayName("Aucun signal → NORMAL, score 0")
    void normal() {
        ResultatFraude r = evaluer(0, 0, 0, 10_000);
        assertThat(r.score()).isZero();
        assertThat(r.palier()).isEqualTo(PalierFraude.NORMAL);
        assertThat(r.motifs()).isEmpty();
    }

    @Test
    @DisplayName("Cumul dépassé seul → ALERTE (l'opération passera)")
    void alerteCumul() {
        ResultatFraude r = evaluer(0, 45_000, 0, 10_000); // 55 000 > 50 000
        assertThat(r.palier()).isEqualTo(PalierFraude.ALERTE);
        assertThat(r.motifs()).containsExactly(MotifFraude.MONTANT_CUMULE_ANORMAL);
        assertThat(r.score()).isEqualTo(30);
    }

    @Test
    @DisplayName("Vélocité dépassée seule → CHALLENGE (re-auth)")
    void challengeVelocite() {
        ResultatFraude r = evaluer(3, 0, 0, 10_000);
        assertThat(r.palier()).isEqualTo(PalierFraude.CHALLENGE);
        assertThat(r.motifs()).containsExactly(MotifFraude.VELOCITE_ELEVEE);
        assertThat(r.score()).isEqualTo(50);
    }

    @Test
    @DisplayName("Vélocité + cumul → GEL (blocage)")
    void gel() {
        ResultatFraude r = evaluer(3, 45_000, 0, 10_000); // 50 + 30 = 80
        assertThat(r.palier()).isEqualTo(PalierFraude.GEL);
        assertThat(r.motifs()).containsExactlyInAnyOrder(
                MotifFraude.VELOCITE_ELEVEE, MotifFraude.MONTANT_CUMULE_ANORMAL);
        assertThat(r.score()).isEqualTo(80);
    }

    @Test
    @DisplayName("Échecs PIN répétés → motif dédié")
    void echecsPin() {
        ResultatFraude r = evaluer(0, 0, 5, 10_000);
        assertThat(r.motifs()).containsExactly(MotifFraude.ECHECS_PIN_REPETES);
        assertThat(r.palier()).isEqualTo(PalierFraude.ALERTE);
    }

    @Test
    @DisplayName("Bornes strictes : nbOps juste sous le seuil et cumul exactement au plafond → NORMAL")
    void bornes() {
        assertThat(evaluer(2, 0, 0, 10_000).palier()).isEqualTo(PalierFraude.NORMAL);   // 2 < 3
        assertThat(evaluer(0, 40_000, 0, 10_000).palier()).isEqualTo(PalierFraude.NORMAL); // 50 000 == plafond, pas >
    }
}
