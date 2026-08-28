package ci.masante.payment.domain.gateway.geniuspay;

import ci.masante.payment.domain.model.StatutGeniusPay;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Le seul endroit qui connaît le vocabulaire du prestataire. Test PUR.
 *
 * <p>La garantie centrale tient en une phrase : <b>un statut inconnu ne vaut jamais succès</b>. Un
 * mappeur qui renverrait « réussi » par défaut solderait des factures sur un mot qu'il ne comprend
 * pas — c'est le genre de défaut qui ne se voit qu'au moment où le prestataire ajoute un statut.</p>
 */
class MappeurStatutGeniusPayTest {

    @Test
    @DisplayName("Un statut inconnu ne vaut JAMAIS succès — et pas davantage échec")
    void statut_inconnu_ne_conclut_rien() {
        // Ni l'un ni l'autre : conclure serait inventer une décision sur une information absente.
        // Vide laisse la transaction où elle est, et la réconciliation repassera.
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("quelque_chose_de_nouveau")).isEmpty();
        assertThat(MappeurStatutGeniusPay.depuisStatutApi(null)).isEmpty();
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("")).isEmpty();
    }

    @Test
    @DisplayName("Les sept statuts documentés sont reconnus, casse indifférente")
    void statuts_documentes() {
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("pending")).contains(StatutGeniusPay.EN_ATTENTE);
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("processing")).contains(StatutGeniusPay.EN_COURS);
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("COMPLETED")).contains(StatutGeniusPay.REUSSIE);
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("failed")).contains(StatutGeniusPay.ECHOUEE);
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("cancelled")).contains(StatutGeniusPay.ANNULEE);
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("expired")).contains(StatutGeniusPay.EXPIREE);
        assertThat(MappeurStatutGeniusPay.depuisStatutApi("refunded")).contains(StatutGeniusPay.REMBOURSEE);
    }

    @Test
    @DisplayName("payment.initiated est explicitement connu et sans effet")
    void payment_initiated_est_nomme() {
        // Il est réellement émis par GeniusPay et absent de la documentation (amendement §4.3.2).
        // Il ne déclenche rien : nous savons déjà qu'une transaction a été initiée, c'est nous qui
        // l'avons demandée. Ce qui compte est qu'il soit NOMMÉ, pas rangé dans un default anonyme.
        assertThat(MappeurStatutGeniusPay.depuisEvenement("payment.initiated")).isEmpty();
        assertThat(MappeurStatutGeniusPay.estConnuSansEffet("payment.initiated")).isTrue();
    }

    @Test
    @DisplayName("Les cashout.* sont ignorés : MaSanté n'est jamais dépositaire des fonds (D8)")
    void cashout_ignore() {
        assertThat(MappeurStatutGeniusPay.estConnuSansEffet("cashout.completed")).isTrue();
        assertThat(MappeurStatutGeniusPay.depuisEvenement("cashout.completed")).isEmpty();
    }

    @Test
    @DisplayName("webhook.test est connu sans effet — il sert la mise en place, pas le métier")
    void webhook_test_connu() {
        assertThat(MappeurStatutGeniusPay.estConnuSansEffet("webhook.test")).isTrue();
    }

    @Test
    @DisplayName("Un événement jamais vu n'est ni traité ni déclaré connu")
    void evenement_totalement_inconnu() {
        // La différence avec payment.initiated est tout l'intérêt : celui-ci n'est pas « connu sans
        // effet », donc il sera journalisé en avertissement. C'est ainsi qu'un événement nouveau se
        // remarque au lieu de disparaître dans la même case que ceux qu'on ignore volontairement.
        assertThat(MappeurStatutGeniusPay.estConnuSansEffet("payout.reversed")).isFalse();
        assertThat(MappeurStatutGeniusPay.depuisEvenement("payout.reversed")).isEmpty();
    }
}
