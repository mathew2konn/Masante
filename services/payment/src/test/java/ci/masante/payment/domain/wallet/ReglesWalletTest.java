package ci.masante.payment.domain.wallet;

import ci.masante.payment.domain.model.WalletStatut;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Règles de débit du wallet (CDC_06 §6) — frontière. Test pur, exécuté au build. */
class ReglesWalletTest {

    @Test
    @DisplayName("Débit autorisé si actif et solde suffisant")
    void debitOk() {
        assertThatCode(() -> ReglesWallet.verifierDebit(WalletStatut.ACTIF, 10_000, 6_000))
                .doesNotThrowAnyException();
    }

    @Test
    @DisplayName("Solde insuffisant → refus (aucun découvert)")
    void soldeInsuffisant() {
        assertThatThrownBy(() -> ReglesWallet.verifierDebit(WalletStatut.ACTIF, 5_000, 6_000))
                .isInstanceOf(SoldeInsuffisantException.class);
    }

    @Test
    @DisplayName("Portefeuille gelé → refus, même avec solde suffisant")
    void gele() {
        assertThatThrownBy(() -> ReglesWallet.verifierDebit(WalletStatut.GELE, 100_000, 6_000))
                .isInstanceOf(WalletGeleException.class);
    }

    @Test
    @DisplayName("Montant non positif → refus")
    void montantInvalide() {
        assertThatThrownBy(() -> ReglesWallet.verifierMontant(0))
                .isInstanceOf(OperationWalletInvalideException.class);
    }
}
