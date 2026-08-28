package ci.masante.payment.domain.gateway.geniuspay;

import ci.masante.payment.config.ProprietesGeniusPay;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.github.tomakehurst.wiremock.WireMockServer;
import com.github.tomakehurst.wiremock.client.WireMock;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.math.BigDecimal;
import java.util.Map;

import static com.github.tomakehurst.wiremock.client.WireMock.aResponse;
import static com.github.tomakehurst.wiremock.client.WireMock.post;
import static com.github.tomakehurst.wiremock.client.WireMock.postRequestedFor;
import static com.github.tomakehurst.wiremock.client.WireMock.urlPathEqualTo;
import static com.github.tomakehurst.wiremock.core.WireMockConfiguration.options;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/**
 * Le client HTTP, éprouvé contre un prestataire simulé au bit près (WireMock, B5).
 *
 * <p><b>Le test qui compte est {@code timeout_ne_rejoue_jamais}.</b> GeniusPay n'offre aucune clé
 * d'idempotence — la vérification en bac à sable l'a confirmé avec des clés valides. Rejouer un
 * {@code POST /payments}, c'est risquer deux transactions pour une facture, donc deux débits sur un
 * patient. Ce test compte les requêtes sortantes et exige <b>exactement une</b>. Il tombe si
 * quelqu'un ajoute un {@code @Retryable} « pour la robustesse ».</p>
 */
class ClientGeniusPayTest {

    private WireMockServer prestataire;
    private ClientGeniusPay client;

    @BeforeEach
    void demarrer() {
        prestataire = new WireMockServer(options().dynamicPort());
        prestataire.start();
        // Délais LARGES par défaut. Un délai serré ici ne prouverait rien et ferait échouer du code
        // sain : le PREMIER appel HTTP d'un processus mesure le chargement de la pile réseau, pas la
        // rapidité du serveur. Seul le test dédié au dépassement resserre, et il le fait APRÈS une
        // requête d'échauffement.
        client = new ClientGeniusPay(proprietes(5000, 10000), new ObjectMapper());
    }

    private ProprietesGeniusPay proprietes(int connexionMs, int lectureMs) {
        ProprietesGeniusPay p = new ProprietesGeniusPay();
        p.setBaseUrl("http://localhost:" + prestataire.port());
        p.setTimeoutConnexionMs(connexionMs);
        p.setTimeoutLectureMs(lectureMs);
        return p;
    }

    @AfterEach
    void arreter() {
        prestataire.stop();
    }

    @Test
    @DisplayName("Un délai dépassé NE REJOUE JAMAIS : exactement une requête sortante")
    void timeout_ne_rejoue_jamais() {
        // Échauffement : le premier appel du processus paie le chargement de la pile réseau. Sans lui,
        // un client aux délais serrés échouerait pour une raison qui n'a rien à voir avec le test.
        prestataire.stubFor(WireMock.get(urlPathEqualTo("/api/v1/merchant/payments/CHAUFFE"))
                .willReturn(aResponse().withStatus(200)
                        .withHeader("Content-Type", "application/json")
                        .withBody("{\"success\":true,\"data\":{\"reference\":\"CHAUFFE\"}}")));
        client.consulter("pk", "sk", "CHAUFFE");
        prestataire.resetRequests();

        prestataire.stubFor(post(urlPathEqualTo("/api/v1/merchant/payments"))
                .willReturn(aResponse().withFixedDelay(4000).withStatus(201).withBody("{}")));

        ClientGeniusPay clientImpatient = new ClientGeniusPay(proprietes(1000, 800), new ObjectMapper());
        assertThatThrownBy(() -> clientImpatient.creerPaiement("pk_test", "sk_test",
                Map.of("amount", 15000L), "MS-ETS1-TEST"))
                .isInstanceOf(GeniusPayInjoignableException.class);

        // LA vérification du lot : une seule requête est partie chez le prestataire.
        prestataire.verify(1, postRequestedFor(urlPathEqualTo("/api/v1/merchant/payments")));
    }

    @Test
    @DisplayName("Une panne réseau est une INCERTITUDE, un refus du prestataire est une RÉPONSE")
    void injoignable_et_refus_sont_deux_choses_differentes() {
        // Confondre les deux ferait soit rejouer un appel peut-être déjà passé, soit abandonner une
        // transaction peut-être créée. Le type de l'exception est ce qui les sépare.
        prestataire.stubFor(post(urlPathEqualTo("/api/v1/merchant/payments"))
                .willReturn(aResponse().withStatus(401)
                        .withHeader("Content-Type", "application/json")
                        .withBody("{\"error\":{\"code\":\"INVALID_API_KEY\",\"message\":\"cle invalide\"}}")));

        assertThatThrownBy(() -> client.creerPaiement("pk_test", "sk_test",
                Map.of("amount", 15000L), "MS-ETS1-TEST"))
                .isInstanceOf(GeniusPayException.class)
                .extracting(e -> ((GeniusPayException) e).getCode())
                .isEqualTo("INVALID_API_KEY");
    }

    @Test
    @DisplayName("Les en-têtes d'authentification sont ceux du contrat, jamais un Bearer")
    void entetes_conformes_au_contrat() {
        // La documentation mentionne un mode Bearer alternatif. Il n'est pas utilisé (amendement §4.3.1).
        prestataire.stubFor(post(urlPathEqualTo("/api/v1/merchant/payments"))
                .willReturn(aResponse().withStatus(201)
                        .withHeader("Content-Type", "application/json")
                        .withBody("{\"success\":true,\"data\":{\"reference\":\"SANDBOX_X\"}}")));

        client.creerPaiement("pk_sandbox_marqueur", "sk_sandbox_marqueur",
                Map.of("amount", 15000L), "MS-ETS1-TEST");

        prestataire.verify(postRequestedFor(urlPathEqualTo("/api/v1/merchant/payments"))
                .withHeader("X-API-Key", WireMock.equalTo("pk_sandbox_marqueur"))
                .withHeader("X-API-Secret", WireMock.equalTo("sk_sandbox_marqueur"))
                .withoutHeader("Authorization"));
    }

    @Test
    @DisplayName("10000.00 vaut 10000 francs ; 10000.50 est une anomalie bloquante")
    void conversion_des_montants() {
        // Le XOF n'a pas de sous-unité. Arrondir reviendrait à décider qu'un demi-franc n'existe pas
        // alors que le prestataire vient d'affirmer le contraire — et l'écart finirait dans le reçu.
        assertThat(ClientGeniusPay.enFrancsEntiers(new BigDecimal("10000.00"))).isEqualTo(10000L);
        assertThat(ClientGeniusPay.enFrancsEntiers(new BigDecimal("10000"))).isEqualTo(10000L);
        assertThat(ClientGeniusPay.enFrancsEntiers(new BigDecimal("0.00"))).isZero();

        assertThatThrownBy(() -> ClientGeniusPay.enFrancsEntiers(new BigDecimal("10000.50")))
                .isInstanceOf(MontantNonEntierException.class);
    }

    @Test
    @DisplayName("Un 404 sur consultation vaut « inconnue », pas une erreur à faire remonter")
    void consultation_introuvable() {
        prestataire.stubFor(WireMock.get(urlPathEqualTo("/api/v1/merchant/payments/SANDBOX_ABSENT"))
                .willReturn(aResponse().withStatus(404)
                        .withHeader("Content-Type", "application/json")
                        .withBody("{\"error\":{\"code\":\"TRANSACTION_NOT_FOUND\"}}")));

        assertThat(client.consulter("pk", "sk", "SANDBOX_ABSENT")).isEmpty();
    }

    @Test
    @DisplayName("Le corps de la réponse d'erreur ne fuit jamais dans le message d'exception")
    void aucune_fuite_du_corps_en_erreur() {
        // Le corps d'erreur d'un prestataire peut porter des données du marchand. Le message doit
        // dire ce qui a échoué, jamais ce que le prestataire a répondu.
        prestataire.stubFor(post(urlPathEqualTo("/api/v1/merchant/payments"))
                .willReturn(aResponse().withStatus(422)
                        .withHeader("Content-Type", "application/json")
                        .withBody("{\"error\":{\"code\":\"VALIDATION_ERROR\"},"
                                  + "\"debug\":\"sk_sandbox_ne_doit_pas_fuiter\"}")));

        assertThatThrownBy(() -> client.creerPaiement("pk", "sk", Map.of("amount", 1L), "MS-X"))
                .isInstanceOf(GeniusPayException.class)
                .hasMessageNotContaining("sk_sandbox_ne_doit_pas_fuiter")
                .hasMessageNotContaining("debug");
    }
}
