package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.carte.simulated.SignatureHmac;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.model.CarteEvenementWebhook;
import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.IdentifiantMarchand;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.StatutGeniusPay;
import ci.masante.payment.repository.CarteEvenementWebhookRepository;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import ci.masante.payment.repository.IdentifiantMarchandRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.mockito.ArgumentCaptor;
import org.springframework.test.util.ReflectionTestUtils;

import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.HashMap;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

/**
 * Réception d'un webhook GeniusPay : vérification, rejet, enregistrement.
 *
 * <p><b>{@code signature_calculee_sur_corps_brut} est le test qui compte.</b> Le payload contient
 * {@code "amount": 10000.00}. L'exemple PHP de la documentation officielle signe un JSON décodé puis
 * ré-encodé : {@code 10000.00} y devient {@code 10000.0}, la chaîne diffère d'un octet, la signature
 * échoue. Toute implémentation qui ré-encode le corps tombe ici, et seulement ici — c'est pour cela
 * que ce test existe et qu'il ne doit pas être « simplifié » avec un payload sans décimales.</p>
 */
class ServiceWebhookGeniusPayTest {

    private static final String SECRET = "whsec_secret_de_test_uniquement";
    private static final String SLUG = "slug-opaque-de-test";

    private IdentifiantMarchandRepository marchands;
    private GestionnaireSecretsMarchand secrets;
    private CarteEvenementWebhookRepository evenements;
    private AntiRejeuWebhook antiRejeu;
    private GeniusPayTransactionRepository transactions;
    private PaiementRepository paiements;
    private GeniusPayTransaction transactionCourante;
    private ServiceWebhookGeniusPay service;

    @BeforeEach
    void preparer() {
        marchands = mock(IdentifiantMarchandRepository.class);
        secrets = mock(GestionnaireSecretsMarchand.class);
        evenements = mock(CarteEvenementWebhookRepository.class);
        antiRejeu = mock(AntiRejeuWebhook.class);
        transactions = mock(GeniusPayTransactionRepository.class);
        paiements = mock(PaiementRepository.class);

        IdentifiantMarchand marchand = new IdentifiantMarchand(UUID.randomUUID(), "ETS-042",
                AdaptateurGeniusPay.PSP, SLUG, "pk_x", new byte[] {1}, new byte[] {2}, (short) 1, "sandbox");
        marchand.poserSecretWebhook(new byte[] {3}, new byte[] {4});
        when(marchands.findBySlugAndActifIsTrue(SLUG)).thenReturn(Optional.of(marchand));
        when(marchands.findBySlugAndActifIsTrue("slug-inconnu")).thenReturn(Optional.empty());
        when(secrets.secretWebhook(any())).thenReturn(SECRET);
        when(evenements.existsByPspAndEvenementId(anyString(), anyString())).thenReturn(false);

        ProprietesGeniusPay proprietes = new ProprietesGeniusPay();
        proprietes.setBaseUrl("http://inutilise");

        service = new ServiceWebhookGeniusPay(marchands, secrets, evenements,
                transactions, paiements,
                mock(TransitionPaiementRepository.class), antiRejeu, mock(ServiceAudit.class),
                mock(ServiceFacturation.class), proprietes, new ObjectMapper(), null);
        // `self` est l'auto-référence par proxy qui, en production, donne leur transaction propre aux
        // deux méthodes d'écriture. Hors contexte Spring il n'y a pas de proxy : on referme la boucle
        // sur l'instance elle-même. Les annotations transactionnelles sont alors sans effet, ce qui
        // est exactement ce qu'on veut ici — on éprouve la décision, pas la propagation.
        ReflectionTestUtils.setField(service, "self", service);
    }

    /** Payload volontairement porteur de {@code 10000.00} — c'est le piège qu'on éprouve. */
    private static byte[] payload() {
        return ("{\"id\":\"evt_2f3a\",\"event\":\"payment.success\",\"environment\":\"sandbox\","
                + "\"data\":{\"reference\":\"SANDBOX_ABC\",\"amount\":10000.00,\"fees\":250.00,"
                + "\"net_amount\":9750.00,\"metadata\":{\"order_id\":\"MS-ETS042-01K\"}}}")
                .getBytes(StandardCharsets.UTF_8);
    }

    /** Signature telle que le prestataire la calcule : HMAC(horodatage + "." + octets bruts). */
    private static String signer(String horodatage, byte[] corps) {
        byte[] aSigner = new byte[horodatage.length() + 1 + corps.length];
        System.arraycopy(horodatage.getBytes(StandardCharsets.UTF_8), 0, aSigner, 0, horodatage.length());
        aSigner[horodatage.length()] = '.';
        System.arraycopy(corps, 0, aSigner, horodatage.length() + 1, corps.length);
        return SignatureHmac.hex(aSigner, SECRET);
    }

    private Map<String, String> entetes(String horodatage, String signature) {
        Map<String, String> e = new HashMap<>();
        e.put("X-Webhook-Signature", signature);
        e.put("X-Webhook-Timestamp", horodatage);
        e.put("X-Webhook-Event", "payment.success");
        e.put("X-Webhook-Environment", "sandbox");
        return e;
    }

    @Test
    @DisplayName("LE TEST QUI COMPTE : la signature est calculée sur les OCTETS REÇUS")
    void signature_calculee_sur_corps_brut() {
        // Une implémentation qui désérialiserait puis ré-encoderait le corps transformerait
        // "amount": 10000.00 en 10000.0. La signature du prestataire, elle, porte sur 10000.00.
        // Elle ne correspondrait plus, et le webhook serait rejeté — donc aucune facture ne serait
        // jamais soldée. Ce test échoue exactement dans ce cas.
        byte[] corps = payload();
        assertThat(new String(corps, StandardCharsets.UTF_8)).contains("10000.00");

        String horodatage = String.valueOf(Instant.now().getEpochSecond());
        int statut = service.recevoir(SLUG, corps, entetes(horodatage, signer(horodatage, corps)), "127.0.0.1");

        assertThat(statut).isEqualTo(200);
    }

    @Test
    @DisplayName("Une signature invalide renvoie 401 et l'événement est enregistré en REJETE_SIGNATURE")
    void signature_invalide_rejetee_401() {
        byte[] corps = payload();
        String horodatage = String.valueOf(Instant.now().getEpochSecond());

        int statut = service.recevoir(SLUG, corps,
                entetes(horodatage, "0".repeat(64)), "127.0.0.1");

        assertThat(statut).isEqualTo(401);
        // Un rejet silencieux rendrait l'incident invisible — or c'est précisément celui qu'on
        // voudra retrouver.
        ArgumentCaptor<CarteEvenementWebhook> capture = ArgumentCaptor.forClass(CarteEvenementWebhook.class);
        verify(evenements).save(capture.capture());
        assertThat(capture.getValue().getStatutTraitement())
                .isEqualTo(ServiceWebhookGeniusPay.REJETE_SIGNATURE);
        assertThat(capture.getValue().getSignatureValide()).isFalse();
    }

    @Test
    @DisplayName("Un slug inconnu répond comme une signature fausse — l'attaquant n'apprend rien")
    void slug_inconnu_indistinguable_d_une_signature_fausse() {
        // Distinguer les deux dirait à qui sonde la route lesquels de ses slugs existent, donc
        // lesquels de nos établissements sont partenaires.
        byte[] corps = payload();
        String horodatage = String.valueOf(Instant.now().getEpochSecond());

        int statut = service.recevoir("slug-inconnu", corps,
                entetes(horodatage, signer(horodatage, corps)), "127.0.0.1");

        assertThat(statut).isEqualTo(401);
    }

    @Test
    @DisplayName("Un horodatage de dix minutes est hors fenêtre : 400, REJETE_HORODATAGE")
    void horodatage_hors_fenetre_rejete() {
        byte[] corps = payload();
        String vieux = String.valueOf(Instant.now().minusSeconds(600).getEpochSecond());

        int statut = service.recevoir(SLUG, corps, entetes(vieux, signer(vieux, corps)), "127.0.0.1");

        assertThat(statut).isEqualTo(400);
        ArgumentCaptor<CarteEvenementWebhook> capture = ArgumentCaptor.forClass(CarteEvenementWebhook.class);
        verify(evenements).save(capture.capture());
        assertThat(capture.getValue().getStatutTraitement())
                .isEqualTo(ServiceWebhookGeniusPay.REJETE_HORODATAGE);
    }

    @Test
    @DisplayName("Un webhook « live » est rejeté en sandbox — contrôle ajouté délibérément")
    void environnement_live_rejete_en_sandbox() {
        // La documentation ne le prévoit pas. Sans ce contrôle, un webhook live pourrait solder une
        // facture de test, ou l'inverse.
        byte[] corps = ("{\"id\":\"evt_live\",\"event\":\"payment.success\",\"environment\":\"live\","
                + "\"data\":{\"reference\":\"LIVE_1\",\"amount\":10000.00}}")
                .getBytes(StandardCharsets.UTF_8);
        String horodatage = String.valueOf(Instant.now().getEpochSecond());
        Map<String, String> e = entetes(horodatage, signer(horodatage, corps));
        e.put("X-Webhook-Environment", "live");

        int statut = service.recevoir(SLUG, corps, e, "127.0.0.1");

        assertThat(statut).isEqualTo(400);
        ArgumentCaptor<CarteEvenementWebhook> capture = ArgumentCaptor.forClass(CarteEvenementWebhook.class);
        verify(evenements).save(capture.capture());
        assertThat(capture.getValue().getStatutTraitement())
                .isEqualTo(ServiceWebhookGeniusPay.REJETE_ENVIRONNEMENT);
    }

    @Test
    @DisplayName("Un en-tête manquant renvoie 400 sans rien écrire")
    void entetes_manquants() {
        // Sans corps ni en-têtes il n'y a pas d'événement, juste du bruit : l'enregistrer offrirait
        // un moyen de remplir la table.
        assertThat(service.recevoir(SLUG, payload(), new HashMap<>(), "127.0.0.1")).isEqualTo(400);
        verify(evenements, never()).save(any());
    }

    @Test
    @DisplayName("Un doublon déjà vu répond 200 sans réécrire — c'est l'idempotence")
    void doublon_ignore() {
        when(antiRejeu.dejaVu(AdaptateurGeniusPay.PSP, "evt_2f3a")).thenReturn(true);
        byte[] corps = payload();
        String horodatage = String.valueOf(Instant.now().getEpochSecond());

        int statut = service.recevoir(SLUG, corps, entetes(horodatage, signer(horodatage, corps)), "127.0.0.1");

        assertThat(statut).isEqualTo(200);
        verify(evenements, never()).save(any());
    }

    @Test
    @DisplayName("Un cashout.* est enregistré sans traitement (D8) et répond 200")
    void cashout_ignore_sans_erreur() {
        byte[] corps = ("{\"id\":\"evt_cashout\",\"event\":\"cashout.completed\","
                + "\"environment\":\"sandbox\",\"data\":{}}").getBytes(StandardCharsets.UTF_8);
        String horodatage = String.valueOf(Instant.now().getEpochSecond());
        Map<String, String> e = entetes(horodatage, signer(horodatage, corps));
        e.put("X-Webhook-Event", "cashout.completed");

        assertThat(service.recevoir(SLUG, corps, e, "127.0.0.1")).isEqualTo(200);

        ArgumentCaptor<CarteEvenementWebhook> capture = ArgumentCaptor.forClass(CarteEvenementWebhook.class);
        verify(evenements).save(capture.capture());
        assertThat(capture.getValue().getStatutTraitement())
                .isEqualTo(ServiceWebhookGeniusPay.IGNORE_NON_GERE);
    }

    @Test
    @DisplayName("Le corps est archivé intégralement — c'est ce qui permet de rejouer la vérification")
    void corps_archive_integralement() {
        byte[] corps = payload();
        String horodatage = String.valueOf(Instant.now().getEpochSecond());

        service.recevoir(SLUG, corps, entetes(horodatage, signer(horodatage, corps)), "127.0.0.1");

        ArgumentCaptor<CarteEvenementWebhook> capture = ArgumentCaptor.forClass(CarteEvenementWebhook.class);
        verify(evenements).save(capture.capture());
        // Octet pour octet : un corps normalisé ne prouverait plus rien en litige.
        assertThat(capture.getValue().getCorpsBrut()).isEqualTo(new String(corps, StandardCharsets.UTF_8));
        assertThat(capture.getValue().getEmpreinteCorps()).hasSize(64);
    }

    /**
     * Monte un événement déjà reçu, prêt à être appliqué, portant le corps donné.
     * Le montant local est toujours 15 000 : c'est à lui que le webhook est confronté.
     */
    private CarteEvenementWebhook evenementPret(String corpsBrut) {
        UUID dbId = UUID.randomUUID();
        UUID paiementId = UUID.randomUUID();
        CarteEvenementWebhook evenement = new CarteEvenementWebhook(AdaptateurGeniusPay.PSP, "evt-montant",
                "payment.success", "RECU", "{}", "x".repeat(64), Instant.now().getEpochSecond(),
                "sandbox", Boolean.TRUE, null, null, "SANDBOX_ABC", "127.0.0.1", corpsBrut);
        ReflectionTestUtils.setField(evenement, "id", dbId);
        when(evenements.findById(dbId)).thenReturn(Optional.of(evenement));

        GeniusPayTransaction transaction = new GeniusPayTransaction(paiementId, "MS-ETS042-01K", null);
        transaction.setStatutGeniusPay(StatutGeniusPay.EN_ATTENTE);
        ReflectionTestUtils.setField(transaction, "id", UUID.randomUUID());
        transactionCourante = transaction;
        when(transactions.findByReferencePasserelle("SANDBOX_ABC")).thenReturn(Optional.of(transaction));
        when(transactions.verrouiller(any())).thenReturn(Optional.of(transaction));

        Paiement paiement = new Paiement("idem-montant", "CORR", 15000, "XOF",
                AdaptateurGeniusPay.CANAL, ObjetPaiement.FACTURE, null, "ETS-042", "PAT-1");
        ReflectionTestUtils.setField(paiement, "id", paiementId);
        when(paiements.findById(paiementId)).thenReturn(Optional.of(paiement));
        return evenement;
    }

    private static String corpsAvecMontant(String montantJson) {
        return "{\"id\":\"evt-montant\",\"event\":\"payment.success\",\"environment\":\"sandbox\","
                + "\"data\":{\"reference\":\"SANDBOX_ABC\",\"amount\":" + montantJson + ","
                + "\"metadata\":{\"order_id\":\"MS-ETS042-01K\"}}}";
    }

    @Test
    @DisplayName("Le montant arrive en CHAÎNE et doit être lu — constaté sur un payment.success réel")
    void montant_en_chaine_est_lu() {
        // La documentation montre un nombre ; le prestataire envoie "15000.00". Sur un nœud textuel,
        // JsonNode.decimalValue() ne parse rien et rend ZÉRO : le contrôle de montant voyait alors
        // « 0 contre 15000 » et refusait de solder une facture pourtant réglée. Trouvé au G2 sur un
        // paiement authentique, jamais par relecture.
        CarteEvenementWebhook evenement = evenementPret(corpsAvecMontant("\"15000.00\""));

        service.appliquer(evenement.getId());

        assertThat(evenement.getStatutTraitement()).isEqualTo("TRAITE");
        assertThat(evenement.getMotifRejet()).isNull();
    }

    @Test
    @DisplayName("Un montant illisible ne vaut JAMAIS zéro : l'événement part en ERREUR, rien n'est soldé")
    void montant_illisible_ne_vaut_jamais_zero() {
        // C'est la moitié qui protège l'autre. Rendre 0 « pour que la lecture passe » solderait une
        // facture sur un montant inventé — exactement ce que le contrôle de divergence existe pour
        // empêcher, contourné par le bas.
        CarteEvenementWebhook evenement = evenementPret(corpsAvecMontant("\"quinze mille\""));

        service.appliquer(evenement.getId());

        assertThat(evenement.getStatutTraitement()).isEqualTo("ERREUR");
        assertThat(evenement.getMotifRejet()).contains("inexploitable");
    }

    @Test
    @DisplayName("Le canal est lu depuis `gateway`, le nom que le webhook emploie réellement")
    void canal_lu_depuis_gateway() {
        // `GET /payments/{ref}` dit `payment_provider` ; le webhook dit `gateway`. Ne lire que le
        // premier laissait le canal NUL jusqu'au passage de la réconciliation — une information que
        // l'événement portait pourtant, et qui figure sur le reçu du patient.
        String corps = "{\"id\":\"evt-montant\",\"event\":\"payment.success\",\"environment\":\"sandbox\","
                + "\"data\":{\"reference\":\"SANDBOX_ABC\",\"amount\":\"15000.00\",\"gateway\":\"wave\","
                + "\"metadata\":{\"order_id\":\"MS-ETS042-01K\"}}}";
        CarteEvenementWebhook evenement = evenementPret(corps);

        service.appliquer(evenement.getId());

        assertThat(evenement.getStatutTraitement()).isEqualTo("TRAITE");
        assertThat(transactionCourante.getCanal()).isEqualTo("wave");
    }

    @Test
    @DisplayName("Un montant divergent reste un incident, chaîne ou nombre — la garde n'a pas bougé")
    void montant_divergent_en_chaine_refuse() {
        CarteEvenementWebhook evenement = evenementPret(corpsAvecMontant("\"14000.00\""));

        service.appliquer(evenement.getId());

        assertThat(evenement.getStatutTraitement()).isEqualTo("ERREUR");
        assertThat(evenement.getMotifRejet()).contains("14000").contains("15000");
    }
}
