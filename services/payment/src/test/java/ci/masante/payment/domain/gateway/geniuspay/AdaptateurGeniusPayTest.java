package ci.masante.payment.domain.gateway.geniuspay;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.RequetePaiement;
import ci.masante.payment.domain.gateway.ResultatPaiement;
import ci.masante.payment.domain.model.IdentifiantMarchand;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import ci.masante.payment.repository.IdentifiantMarchandRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.service.GestionnaireSecretsMarchand;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.github.tomakehurst.wiremock.WireMockServer;
import com.github.tomakehurst.wiremock.verification.LoggedRequest;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

import static com.github.tomakehurst.wiremock.client.WireMock.aResponse;
import static com.github.tomakehurst.wiremock.client.WireMock.post;
import static com.github.tomakehurst.wiremock.client.WireMock.postRequestedFor;
import static com.github.tomakehurst.wiremock.client.WireMock.urlPathEqualTo;
import static com.github.tomakehurst.wiremock.core.WireMockConfiguration.options;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

/**
 * Ce que l'adaptateur envoie réellement au prestataire.
 *
 * <p>Deux garanties sont vérifiées sur le <b>corps émis</b>, pas sur une intention : aucune donnée
 * médicale n'en sort, et {@code metadata.order_id} y figure toujours. La seconde n'est pas une
 * commodité : c'est le seul lien qui permette de rattacher un webhook à une transaction dont on
 * n'aurait pas la référence prestataire (§7.4.b).</p>
 */
class AdaptateurGeniusPayTest {

    private WireMockServer prestataire;
    private AdaptateurGeniusPay adaptateur;
    private final ObjectMapper json = new ObjectMapper();

    @BeforeEach
    void demarrer() {
        prestataire = new WireMockServer(options().dynamicPort());
        prestataire.start();

        ProprietesGeniusPay p = new ProprietesGeniusPay();
        p.setBaseUrl("http://localhost:" + prestataire.port());
        // Délais larges : ce fichier éprouve CE QUI EST ENVOYÉ, jamais des durées. Un délai serré y
        // ferait échouer du code sain au premier appel du processus (chargement de la pile réseau).
        p.setTimeoutConnexionMs(5000);
        p.setTimeoutLectureMs(10000);
        p.setSuccessUrl("https://masante.example/paiement/ok");
        p.setErrorUrl("https://masante.example/paiement/ko");

        IdentifiantMarchand marchand = new IdentifiantMarchand(UUID.randomUUID(), "ETS-042",
                AdaptateurGeniusPay.PSP, "slug-opaque", "pk_sandbox_x",
                new byte[] {1}, new byte[] {2}, (short) 1, "sandbox");
        IdentifiantMarchandRepository marchands = mock(IdentifiantMarchandRepository.class);
        when(marchands.findByEtablissementRefAndPspAndActifIsTrue("ETS-042", AdaptateurGeniusPay.PSP))
                .thenReturn(Optional.of(marchand));
        when(marchands.findByEtablissementRefAndPspAndActifIsTrue("ETS-SANS-COMPTE", AdaptateurGeniusPay.PSP))
                .thenReturn(Optional.empty());

        GestionnaireSecretsMarchand secrets = mock(GestionnaireSecretsMarchand.class);
        when(secrets.cleSecrete(any())).thenReturn("sk_sandbox_dechiffre");

        adaptateur = new AdaptateurGeniusPay(new ClientGeniusPay(p, json), marchands, secrets,
                mock(GeniusPayTransactionRepository.class), mock(PaiementRepository.class), p,
                "Règlement MaSanté");
    }

    @AfterEach
    void arreter() {
        prestataire.stop();
    }

    private void prestataireAccepte() {
        prestataire.stubFor(post(urlPathEqualTo("/api/v1/merchant/payments"))
                .willReturn(aResponse().withStatus(201)
                        .withHeader("Content-Type", "application/json")
                        .withBody("""
                                {"success":true,"data":{
                                  "reference":"SANDBOX_ABC123",
                                  "amount":15000.00,
                                  "currency":"XOF",
                                  "status":null,
                                  "checkout_url":"https://geniuspay.ci/checkout/SANDBOX_ABC123",
                                  "expires_at":"2026-08-28T00:42:37+00:00",
                                  "environment":"sandbox",
                                  "metadata":{"order_id":"MS-ETS042-01K"}
                                }}""")));
    }

    private RequetePaiement requete() {
        return new RequetePaiement("MS-ETS042-01K", 15000, "XOF", AdaptateurGeniusPay.CANAL,
                ObjetPaiement.FACTURE, "+2250709090909", "CORR-1", "ETS-042", UUID.randomUUID());
    }

    private JsonNode corpsEmis() throws Exception {
        List<LoggedRequest> requetes = prestataire.findAll(
                postRequestedFor(urlPathEqualTo("/api/v1/merchant/payments")));
        assertThat(requetes).hasSize(1);
        return json.readTree(requetes.get(0).getBody());
    }

    @Test
    @DisplayName("metadata.order_id est toujours présent — c'est lui qui rattache un webhook orphelin")
    void order_id_toujours_present() throws Exception {
        prestataireAccepte();
        adaptateur.payer(requete());
        assertThat(corpsEmis().path("metadata").path("order_id").asText()).isEqualTo("MS-ETS042-01K");
    }

    @Test
    @DisplayName("Aucune donnée médicale ne sort : le libellé est générique et constant")
    void aucune_donnee_medicale_sortante() throws Exception {
        prestataireAccepte();
        adaptateur.payer(requete());
        JsonNode corps = corpsEmis();

        assertThat(corps.path("description").asText()).isEqualTo("Règlement MaSanté");
        // L'objet du paiement (FACTURE, ORDONNANCE, ANALYSE…) dit quelque chose du soin : il ne sort
        // pas. Le corps entier est inspecté, pas seulement le champ description.
        String brut = corps.toString();
        assertThat(brut).doesNotContain("ORDONNANCE").doesNotContain("ANALYSE")
                .doesNotContain("FACTURE").doesNotContain("RADIOLOGIE");
    }

    @Test
    @DisplayName("Ni nom ni téléphone du patient ne sont envoyés, bien que le contrat les accepte")
    void aucune_donnee_personnelle_sortante() throws Exception {
        // Le corps du webhook est archivé INTÉGRALEMENT pour permettre de rejouer une vérification de
        // signature en litige. Ne rien envoyer garantit que rien de personnel ne peut y revenir : on
        // ferme le problème à la source, au lieu d'un masquage qui casserait l'empreinte du corps.
        prestataireAccepte();
        adaptateur.payer(requete());
        JsonNode corps = corpsEmis();

        assertThat(corps.toString()).doesNotContain("0709090909").doesNotContain("+225");
        assertThat(corps.path("customer").has("phone")).isFalse();
        assertThat(corps.path("customer").has("name")).isFalse();
        assertThat(corps.path("customer").path("country").asText()).isEqualTo("CI");
    }

    @Test
    @DisplayName("payment_method est OMIS — c'est son absence qui ouvre la page de checkout")
    void payment_method_omis() throws Exception {
        // Le renseigner choisirait l'opérateur à la place du patient.
        prestataireAccepte();
        adaptateur.payer(requete());
        assertThat(corpsEmis().has("payment_method")).isFalse();
    }

    @Test
    @DisplayName("L'échéance retenue est celle du prestataire, pas un « maintenant + 24 h »")
    void echeance_du_prestataire() {
        // La vérification en bac à sable a montré TRENTE MINUTES là où la documentation annonce
        // vingt-quatre heures. Une échéance recopiée de la documentation ferait tenir pour ouvert un
        // lien déjà mort.
        prestataireAccepte();
        ResultatPaiement resultat = adaptateur.payer(requete());
        assertThat(resultat.checkout().expireLe())
                .isEqualTo(java.time.OffsetDateTime.parse("2026-08-28T00:42:37+00:00").toInstant());
    }

    @Test
    @DisplayName("Une création sans statut vaut EN_ATTENTE, jamais un succès")
    void statut_absent_vaut_en_attente() {
        // Le bac à sable renvoie `status: null` à la création. Un checkout créé sans statut attend :
        // c'est le seul état déductible sans rien inventer, et surtout ce n'est pas SUCCESS.
        prestataireAccepte();
        ResultatPaiement resultat = adaptateur.payer(requete());
        assertThat(resultat.statut()).isEqualTo(PaiementStatut.PENDING);
        assertThat(resultat.referenceOperateur()).isEqualTo("SANDBOX_ABC123");
    }

    @Test
    @DisplayName("Un établissement sans compte marchand est refusé, jamais basculé sur un autre compte")
    void etablissement_sans_compte_refuse() {
        // Un repli silencieux ferait arriver l'argent d'un partenaire sur le compte d'un autre.
        RequetePaiement sansCompte = new RequetePaiement("MS-X-01K", 15000, "XOF",
                AdaptateurGeniusPay.CANAL, ObjetPaiement.FACTURE, null, null, "ETS-SANS-COMPTE", null);
        assertThatThrownBy(() -> adaptateur.payer(sansCompte))
                .isInstanceOf(MarchandIntrouvableException.class);
        assertThat(prestataire.getAllServeEvents()).isEmpty();
    }

    @Test
    @DisplayName("L'adaptateur ne revendique que le canal « geniuspay »")
    void canal_distinct() {
        // Revendiquer orange_money ou wave ferait dépendre le choix de passerelle de l'ordre
        // d'injection des beans, et surtout ce serait faux : nous n'appelons pas l'opérateur.
        assertThat(adaptateur.supporte("geniuspay")).isTrue();
        assertThat(adaptateur.supporte("GENIUSPAY")).isTrue();
        assertThat(adaptateur.supporte("orange_money")).isFalse();
        assertThat(adaptateur.supporte("wave")).isFalse();
        assertThat(adaptateur.supporte("carte")).isFalse();
    }

    @Test
    @DisplayName("Le remboursement lève au lieu de renvoyer un faux succès")
    void remboursement_hors_perimetre() {
        assertThatThrownBy(() -> adaptateur.rembourser(
                new ci.masante.payment.domain.gateway.RequeteRemboursement("MS-X", "SANDBOX_X", 1000, "motif")))
                .isInstanceOf(UnsupportedOperationException.class);
    }
}
