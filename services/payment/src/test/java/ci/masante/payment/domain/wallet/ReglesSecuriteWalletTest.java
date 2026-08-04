package ci.masante.payment.domain.wallet;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Règles de sécurité pures du wallet (CDC_06 §6.4) — frontière. Test pur, exécuté au build. */
class ReglesSecuriteWalletTest {

    // --- format du PIN ------------------------------------------------------------------------

    @Test
    @DisplayName("PIN de 4 à 6 chiffres accepté")
    void pinFormatOk() {
        assertThatCode(() -> ReglesSecuriteWallet.verifierFormatPin("1234")).doesNotThrowAnyException();
        assertThatCode(() -> ReglesSecuriteWallet.verifierFormatPin("123456")).doesNotThrowAnyException();
    }

    @Test
    @DisplayName("PIN trop court, trop long, non numérique ou null → refus")
    void pinFormatInvalide() {
        for (String mauvais : new String[] {null, "", "123", "1234567", "12a4", "abcd"}) {
            assertThatThrownBy(() -> ReglesSecuriteWallet.verifierFormatPin(mauvais))
                    .isInstanceOf(PinInvalideException.class);
        }
    }

    // --- limites ------------------------------------------------------------------------------

    @Test
    @DisplayName("Limite par opération : refus au-delà du plafond, OK en-dessous")
    void limiteOperation() {
        assertThatCode(() -> ReglesSecuriteWallet.verifierLimiteOperation(500_000, 500_000))
                .doesNotThrowAnyException();
        assertThatThrownBy(() -> ReglesSecuriteWallet.verifierLimiteOperation(500_001, 500_000))
                .isInstanceOf(LimiteDepasseeException.class);
    }

    @Test
    @DisplayName("Limite journalière : le cumul déjà consommé + le montant est contrôlé")
    void limiteJournaliere() {
        // déjà 900 000 consommés aujourd'hui, plafond 1 000 000 → 100 000 passe, 100 001 refusé
        assertThatCode(() -> ReglesSecuriteWallet.verifierLimiteJournaliere(900_000, 100_000, 1_000_000))
                .doesNotThrowAnyException();
        assertThatThrownBy(() -> ReglesSecuriteWallet.verifierLimiteJournaliere(900_000, 100_001, 1_000_000))
                .isInstanceOf(LimiteDepasseeException.class);
    }

    @Test
    @DisplayName("Limite mensuelle : idem sur la fenêtre du mois")
    void limiteMensuelle() {
        assertThatThrownBy(() -> ReglesSecuriteWallet.verifierLimiteMensuelle(4_999_999, 2, 5_000_000))
                .isInstanceOf(LimiteDepasseeException.class);
    }

    @Test
    @DisplayName("Plafond <= 0 = illimité : aucun contrôle")
    void plafondIllimite() {
        assertThatCode(() -> {
            ReglesSecuriteWallet.verifierLimiteOperation(9_000_000_000L, 0);
            ReglesSecuriteWallet.verifierLimiteJournaliere(9_000_000_000L, 9_000_000_000L, -1);
            ReglesSecuriteWallet.verifierLimiteMensuelle(9_000_000_000L, 9_000_000_000L, 0);
        }).doesNotThrowAnyException();
    }

    // --- seuil OTP ----------------------------------------------------------------------------

    @Test
    @DisplayName("OTP requis strictement au-delà du seuil")
    void otpRequis() {
        assertThat(ReglesSecuriteWallet.otpRequis(100_000, 100_000)).isFalse(); // égal au seuil : non
        assertThat(ReglesSecuriteWallet.otpRequis(100_001, 100_000)).isTrue();
        assertThat(ReglesSecuriteWallet.otpRequis(1_000_000, 0)).isFalse();     // seuil <= 0 : jamais
    }
}
