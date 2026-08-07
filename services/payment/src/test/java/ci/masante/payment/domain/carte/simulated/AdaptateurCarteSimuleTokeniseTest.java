package ci.masante.payment.domain.carte.simulated;

import ci.masante.payment.domain.carte.DemandeCarte;
import ci.masante.payment.domain.carte.Devise;
import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.carte.ResultatInitiation;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.Map;

import static org.assertj.core.api.Assertions.assertThat;

/** Adaptateur carte simulé TOKENISÉ (P5.4a) — les 5 références de test produisent le bon résultat. */
class AdaptateurCarteSimuleTokeniseTest {

    private final AdaptateurCarteSimuleTokenise adaptateur = new AdaptateurCarteSimuleTokenise(new ObjectMapper());

    private DemandeCarte demande(String ref) {
        return new DemandeCarte(ref, Montant.de(20_000, Devise.XOF), "user-1", null);
    }

    @Test
    @DisplayName("frictionless → Frictionless (avec NTID, marque, last4)")
    void frictionless() {
        ResultatInitiation r = adaptateur.initier(demande("tok_test_frictionless"));
        assertThat(r).isInstanceOf(ResultatInitiation.Frictionless.class);
        var f = (ResultatInitiation.Frictionless) r;
        assertThat(f.ntid()).isNotBlank();
        assertThat(f.marque()).isEqualTo("VISA");
        assertThat(f.last4()).isEqualTo("4242");
    }

    @Test
    @DisplayName("challenge → DefiRequis (TTL futur) ; recupererStatut → AUTORISE")
    void challenge() {
        ResultatInitiation r = adaptateur.initier(demande("tok_test_challenge"));
        assertThat(r).isInstanceOf(ResultatInitiation.DefiRequis.class);
        var d = (ResultatInitiation.DefiRequis) r;
        assertThat(d.challengeRef()).isNotBlank();
        assertThat(d.expireLe()).isAfter(Instant.now());
        assertThat(adaptateur.recupererStatut(d.refPasserelle()).issue()).isEqualTo(IssuePasserelle.AUTORISE);
    }

    @Test
    @DisplayName("refus → Refusee à l'initiation")
    void refus() {
        ResultatInitiation r = adaptateur.initier(demande("tok_test_refus"));
        assertThat(r).isInstanceOf(ResultatInitiation.Refusee.class);
        assertThat(((ResultatInitiation.Refusee) r).codeRefus()).isEqualTo("card_declined");
    }

    @Test
    @DisplayName("authfail → DefiRequis à l'init, puis REFUSE à la finalisation")
    void authfail() {
        var d = (ResultatInitiation.DefiRequis) adaptateur.initier(demande("tok_test_authfail"));
        assertThat(adaptateur.recupererStatut(d.refPasserelle()).issue()).isEqualTo(IssuePasserelle.REFUSE);
    }

    @Test
    @DisplayName("expire → DefiRequis avec TTL déjà dépassé")
    void expire() {
        var d = (ResultatInitiation.DefiRequis) adaptateur.initier(demande("tok_test_expire"));
        assertThat(d.expireLe()).isBefore(Instant.now());
        assertThat(adaptateur.recupererStatut(d.refPasserelle()).issue()).isEqualTo(IssuePasserelle.EXPIRE);
    }

    @Test
    @DisplayName("verifierSignature : signature valide acceptée, altérée d'un octet rejetée")
    void signature() {
        byte[] corps = "{\"evenementId\":\"evt_1\"}".getBytes(StandardCharsets.UTF_8);
        String valide = SignatureHmac.hex(corps, AdaptateurCarteSimuleTokenise.SECRET_DEV);
        assertThat(adaptateur.verifierSignature(corps, Map.of("X-Signature", valide))).isTrue();

        String altere = ("0" + valide.substring(1)).equals(valide) ? "1" + valide.substring(1) : "0" + valide.substring(1);
        assertThat(adaptateur.verifierSignature(corps, Map.of("X-Signature", altere))).isFalse();
        assertThat(adaptateur.verifierSignature(corps, Map.of())).isFalse();
    }
}
