package ci.masante.payment.service;

import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.TransitionTerminaleEvenement;
import ci.masante.payment.domain.notification.TypeNotification;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.mockito.ArgumentCaptor;

import java.time.Instant;
import java.util.Map;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyMap;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verify;

/**
 * Lot 6 — ce qui est enfilé dans l'outbox à chaque issue de paiement. Test PUR (Mockito).
 *
 * <p>Ce composant ENFILE, il n'envoie rien : le vecteur porte donc sur le type de la ligne et sur
 * le contenu exact de la charge — c'est-à-dire sur ce que le partenaire lira.</p>
 */
class NotificateurFacturationTest {

    private static final UUID PAIEMENT = UUID.randomUUID();
    private static final Instant QUAND = Instant.parse("2026-08-27T10:15:30Z");

    private ServiceNotifications notifications;
    private NotificateurFacturation notificateur;

    @BeforeEach
    void setup() {
        notifications = mock(ServiceNotifications.class);
        notificateur = new NotificateurFacturation(notifications);
    }

    private static final UUID FACTURE = UUID.randomUUID();

    @SuppressWarnings("unchecked")
    private Map<String, Object> chargeEmise(PaiementStatut statut) {
        return chargeEmise(statut, "CI-ETS000001", FACTURE, 250L, "geniuspay");
    }

    @SuppressWarnings("unchecked")
    private Map<String, Object> chargeEmise(PaiementStatut statut, String etablissementRef,
                                            UUID factureId, Long fraisPasserelle) {
        return chargeEmise(statut, etablissementRef, factureId, fraisPasserelle, "geniuspay");
    }

    @SuppressWarnings("unchecked")
    private Map<String, Object> chargeEmise(PaiementStatut statut, String etablissementRef,
                                            UUID factureId, Long fraisPasserelle, String canal) {
        notificateur.surTransitionTerminale(new TransitionTerminaleEvenement(
                PAIEMENT, "CORR-42", 15000, "XOF", statut, QUAND,
                etablissementRef, factureId, fraisPasserelle, canal));

        ArgumentCaptor<Map<String, Object>> charge = ArgumentCaptor.forClass(Map.class);
        verify(notifications).emettre(
                eq(TypeNotification.PAIEMENT_NOTIFICATION_LARAVEL),
                eq("paiement"),
                eq(PAIEMENT),
                eq(NotificateurFacturation.DESTINATAIRE),
                charge.capture());

        return charge.getValue();
    }

    // ── 1. Ligne d'outbox du bon type sur transition terminale ──────────────────────────────

    @Test
    @DisplayName("La ligne porte le type SYSTÈME, jamais un type de notification humaine")
    void test_notification_ecrite_dans_outbox_avec_bon_type_sur_transition_terminale() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS);

        assertThat(charge.get("paiementId")).isEqualTo(PAIEMENT.toString());
        assertThat(charge.get("correlationId")).isEqualTo("CORR-42");
        assertThat(charge.get("montant")).isEqualTo(15000L);
        assertThat(charge.get("devise")).isEqualTo("XOF");
        assertThat(charge.get("dateTransaction")).isEqualTo("2026-08-27T10:15:30Z");
        // Vocabulaire de la machine partagée (@masante/shared), pas un troisième dialecte.
        assertThat(charge.get("statut")).isEqualTo("SUCCESS");
    }

    @Test
    @DisplayName("Un échec est notifié comme un succès : c'est l'ISSUE qui compte")
    void echecAussi() {
        assertThat(chargeEmise(PaiementStatut.FAILED).get("statut")).isEqualTo("FAILED");
    }

    // ── 3. Frais — B4/S3 (2026-09-04) : réels quand connus, NULL sinon, jamais 0 par défaut ────

    @Test
    @DisplayName("Frais de passerelle connus → recopiés tels quels, jamais réestimés")
    void test_frais_passerelle_connus_recopies() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS, "CI-ETS000001", FACTURE, 250L);

        assertThat(charge.get("fraisPasserelle")).isEqualTo(250L);
    }

    @Test
    @DisplayName("Frais de passerelle inconnus → NULL dans la charge, jamais 0 inventé")
    void test_frais_passerelle_inconnus_restent_nuls() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS, "CI-ETS000001", FACTURE, null);

        assertThat(charge).containsKey("fraisPasserelle");
        assertThat(charge.get("fraisPasserelle")).isNull();
    }

    @Test
    @DisplayName("Frais prestataire à 0, explicite : ce canal n'a qu'un seul poste de frais réel")
    void test_frais_prestataire_toujours_zero_un_seul_poste_sur_ce_canal() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS);

        assertThat(charge).containsKey("fraisPrestataire");
        assertThat(charge.get("fraisPrestataire")).isEqualTo(0);
    }

    // ── 4. Établissement et facture — B4/S2 (2026-09-04) : recopiés, jamais devinés ────────────

    @Test
    @DisplayName("etablissementRef connu → recopié tel quel, à charge pour Laravel de le résoudre")
    void test_etablissement_ref_connu_recopie() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS, "CI-ETS000001", FACTURE, 250L);

        assertThat(charge.get("etablissementRef")).isEqualTo("CI-ETS000001");
    }

    @Test
    @DisplayName("etablissementRef absent de l'agrégat (canaux simulés) → NULL, jamais deviné")
    void test_etablissement_ref_absent_reste_nul() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS, null, null, null);

        assertThat(charge).containsKey("etablissementRef");
        assertThat(charge.get("etablissementRef")).isNull();
    }

    @Test
    @DisplayName("factureId (interne au microservice) recopié en chaîne, jamais réinterprété")
    void test_facture_id_recopie_en_chaine() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS, "CI-ETS000001", FACTURE, 250L);

        assertThat(charge.get("factureId")).isEqualTo(FACTURE.toString());
    }

    @Test
    @DisplayName("Rien n'est jamais deviné : facturePatientId n'existe pas dans ce domaine, absent")
    void aucuneDonneePatientInventee() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS);

        assertThat(charge)
                .as("Le domaine ne porte pas la facture PATIENT (elle vit côté Laravel) : "
                    + "l'inventer rattacherait un règlement à la mauvaise facture")
                .doesNotContainKey("facturePatientId");
    }

    @Test
    @DisplayName("Le composant enfile, il ne livre pas")
    void nEnvoieRien() {
        notificateur.surTransitionTerminale(new TransitionTerminaleEvenement(
                PAIEMENT, "CORR-42", 15000, "XOF", PaiementStatut.SUCCESS, QUAND,
                "CI-ETS000001", FACTURE, 250L, "geniuspay"));

        verify(notifications).emettre(any(), anyString(), any(), anyString(), anyMap());
    }

    // ── 5. Canal — B4 (2026-09-04, ajouté en cours d'exécution) ────────────────────────────────

    @Test
    @DisplayName("Le canal GeniusPay est recopié tel quel — c'est lui qui décidera côté Laravel")
    void test_canal_geniuspay_recopie() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS, "CI-ETS000001", FACTURE, 250L, "geniuspay");

        assertThat(charge.get("canal")).isEqualTo("geniuspay");
    }

    @Test
    @DisplayName("Un canal carte porte aussi etablissementRef — SEUL le canal distingue les deux")
    void test_canal_carte_porte_aussi_etablissement_ref() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS, "CI-ETS000001", FACTURE, null, "carte");

        assertThat(charge.get("canal")).isEqualTo("carte");
        assertThat(charge.get("etablissementRef")).isEqualTo("CI-ETS000001");
    }
}
