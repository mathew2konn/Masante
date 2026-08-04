package ci.masante.payment;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.nio.charset.StandardCharsets;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Garde-fou : le crédit du cashback doit rester DÉSACTIVÉ par défaut (prêt à activer §11). Ce test
 * échoue si quelqu'un inverse le défaut dans {@code application.yml} sans passer par une activation
 * explicite — la boucle d'abus resterait sinon ouverte.
 */
class CashbackFlagDefautTest {

    @Test
    @DisplayName("credit-enabled par défaut = false dans application.yml")
    void defautDesactive() throws Exception {
        String yml = new String(
                getClass().getResourceAsStream("/application.yml").readAllBytes(), StandardCharsets.UTF_8);
        assertThat(yml).contains("credit-enabled: ${WALLET_CASHBACK_CREDIT_ENABLED:false}");
    }
}
