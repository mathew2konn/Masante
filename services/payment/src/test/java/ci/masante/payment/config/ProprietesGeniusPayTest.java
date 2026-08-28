package ci.masante.payment.config;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/**
 * Les deux garde-fous de démarrage. Ils partagent une même exigence : <b>échouer bruyamment</b>.
 *
 * <p>Le second cas a été trouvé au démarrage réel, pas par relecture. Un placeholder non résolu
 * traverse la validation {@code @NotBlank} sans broncher — il n'est pas vide, il vaut littéralement
 * {@code ${GENIUSPAY_BASE_URL}} — et le service démarre en apparence sain pour n'échouer qu'au
 * premier paiement. Une promesse d'échec bruyant qui ne fait aucun bruit est pire que pas de
 * promesse du tout : on croit la garantie active.</p>
 */
class ProprietesGeniusPayTest {

    private ProprietesGeniusPay proprietes(String baseUrl, String environnement) {
        ProprietesGeniusPay p = new ProprietesGeniusPay();
        p.setBaseUrl(baseUrl);
        p.setEnvironnement(environnement);
        return p;
    }

    @Test
    @DisplayName("Une base non résolue fait ÉCHOUER le démarrage, elle ne passe pas pour une URL")
    void placeholder_non_resolu_refuse() {
        assertThatThrownBy(() -> proprietes("${GENIUSPAY_BASE_URL}", "sandbox").verifierAuDemarrage())
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("GENIUSPAY_BASE_URL");
    }

    @Test
    @DisplayName("Une base absente ou non absolue est refusée")
    void base_absente_ou_relative_refusee() {
        assertThatThrownBy(() -> proprietes(null, "sandbox").verifierAuDemarrage())
                .isInstanceOf(IllegalStateException.class);
        assertThatThrownBy(() -> proprietes("geniuspay.ci", "sandbox").verifierAuDemarrage())
                .isInstanceOf(IllegalStateException.class);
    }

    @Test
    @DisplayName("D7 : tout environnement autre que sandbox fait échouer le démarrage")
    void hors_sandbox_refuse() {
        // Un service qui démarrerait en « live » parce qu'une variable a été mal saisie encaisserait
        // de l'argent réel sur une intégration jamais validée pour cela.
        assertThatThrownBy(() -> proprietes("https://geniuspay.ci", "live").verifierAuDemarrage())
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("sandbox");
    }

    @Test
    @DisplayName("Une configuration correcte démarre")
    void configuration_correcte() {
        assertThatCode(() -> proprietes("https://geniuspay.ci", "sandbox").verifierAuDemarrage())
                .doesNotThrowAnyException();
    }
}
