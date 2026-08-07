package ci.masante.payment.domain.carte.simulated;

import ci.masante.payment.domain.carte.DemandeCarte;
import ci.masante.payment.domain.carte.Devise;
import ci.masante.payment.domain.carte.EvenementWebhook;
import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.carte.ResultatInitiation;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.nio.charset.StandardCharsets;

import static org.assertj.core.api.Assertions.assertThat;

/** Adaptateur carte simulé REDIRIGÉ (P5.4a) — les 4 références de test + parsing d'événement webhook. */
class AdaptateurCarteSimuleRedirigeTest {

    private final AdaptateurCarteSimuleRedirige adaptateur = new AdaptateurCarteSimuleRedirige(new ObjectMapper());

    private DemandeCarte demande(String ref) {
        return new DemandeCarte(ref, Montant.de(20_000, Devise.XOF), "user-1", "https://retour.local/ok");
    }

    @Test
    @DisplayName("succes → RedirectionRequise (URL) ; recupererStatut → AUTORISE")
    void succes() {
        var r = (ResultatInitiation.RedirectionRequise) adaptateur.initier(demande("red_test_succes"));
        assertThat(r.urlPaiement()).contains("http");
        assertThat(adaptateur.recupererStatut(r.refPasserelle()).issue()).isEqualTo(IssuePasserelle.AUTORISE);
    }

    @Test
    @DisplayName("abandon → RedirectionRequise ; recupererStatut reste EN_ATTENTE (aucun webhook)")
    void abandon() {
        var r = (ResultatInitiation.RedirectionRequise) adaptateur.initier(demande("red_test_abandon"));
        assertThat(adaptateur.recupererStatut(r.refPasserelle()).issue()).isEqualTo(IssuePasserelle.EN_ATTENTE);
    }

    @Test
    @DisplayName("refus → RedirectionRequise ; recupererStatut → REFUSE")
    void refus() {
        var r = (ResultatInitiation.RedirectionRequise) adaptateur.initier(demande("red_test_refus"));
        assertThat(adaptateur.recupererStatut(r.refPasserelle()).issue()).isEqualTo(IssuePasserelle.REFUSE);
    }

    @Test
    @DisplayName("timeout → RedirectionRequise ; recupererStatut → AUTORISE (le webhook tardif sera rejeté au service)")
    void timeout() {
        var r = (ResultatInitiation.RedirectionRequise) adaptateur.initier(demande("red_test_timeout"));
        assertThat(adaptateur.recupererStatut(r.refPasserelle()).issue()).isEqualTo(IssuePasserelle.AUTORISE);
    }

    @Test
    @DisplayName("parserEvenement : normalise le corps JSON en EvenementWebhook")
    void parsing() {
        byte[] corps = ("{\"evenementId\":\"evt_9\",\"type\":\"payment.updated\","
                + "\"refPasserelle\":\"SIMRD-SUCCES-XYZ\",\"issue\":\"AUTORISE\",\"marque\":\"MASTERCARD\","
                + "\"last4\":\"4444\"}").getBytes(StandardCharsets.UTF_8);
        EvenementWebhook e = adaptateur.parserEvenement(corps);
        assertThat(e.evenementId()).isEqualTo("evt_9");
        assertThat(e.refPasserelle()).isEqualTo("SIMRD-SUCCES-XYZ");
        assertThat(e.issue()).isEqualTo(IssuePasserelle.AUTORISE);
        assertThat(e.last4()).isEqualTo("4444");
    }
}
