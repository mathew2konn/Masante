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

    @SuppressWarnings("unchecked")
    private Map<String, Object> chargeEmise(PaiementStatut statut) {
        notificateur.surTransitionTerminale(new TransitionTerminaleEvenement(
                PAIEMENT, "CORR-42", 15000, "XOF", statut, QUAND));

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

    // ── 3. Frais toujours à zéro, explicites ────────────────────────────────────────────────

    @Test
    @DisplayName("Frais à 0, explicites et jamais omis — coût réel d'une passerelle qui n'existe pas")
    void test_frais_toujours_zero_pour_adaptateur_simule() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS);

        assertThat(charge).containsKey("fraisPasserelle").containsKey("fraisPrestataire");
        assertThat(charge.get("fraisPasserelle")).isEqualTo(0);
        assertThat(charge.get("fraisPrestataire")).isEqualTo(0);
    }

    @Test
    @DisplayName("Rien n'est deviné : ni structure, ni facture patient")
    void aucuneDonneeInventee() {
        Map<String, Object> charge = chargeEmise(PaiementStatut.SUCCESS);

        assertThat(charge)
                .as("Le domaine ne porte pas ces identifiants : les fabriquer rattacherait "
                    + "des commissions à la mauvaise structure")
                .doesNotContainKeys("structureSanitaireId", "facturePatientId", "etablissementRef");
    }

    @Test
    @DisplayName("Le composant enfile, il ne livre pas")
    void nEnvoieRien() {
        notificateur.surTransitionTerminale(new TransitionTerminaleEvenement(
                PAIEMENT, "CORR-42", 15000, "XOF", PaiementStatut.SUCCESS, QUAND));

        verify(notifications).emettre(any(), anyString(), any(), anyString(), anyMap());
    }
}
