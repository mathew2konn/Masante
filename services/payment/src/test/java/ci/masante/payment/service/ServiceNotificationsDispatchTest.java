package ci.masante.payment.service;

import ci.masante.payment.domain.model.NotificationSortie;
import ci.masante.payment.domain.notification.EnvoiNotification;
import ci.masante.payment.domain.notification.MessageNotification;
import ci.masante.payment.domain.notification.NotificationSysteme;
import ci.masante.payment.domain.notification.NotificationSystemeException;
import ci.masante.payment.domain.notification.ResultatEnvoi;
import ci.masante.payment.domain.notification.StatutNotification;
import ci.masante.payment.domain.notification.TypeNotification;
import ci.masante.payment.repository.NotificationSortieRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.mockito.ArgumentCaptor;
import org.springframework.test.util.ReflectionTestUtils;

import java.util.Optional;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.doThrow;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

/**
 * Lot 6 — le relais distingue deux natures de destinataire sans changer le chemin humain.
 *
 * <p>Le vecteur central est le n°4 du prompt : une notification humaine doit passer par
 * {@link EnvoiNotification} <b>exactement comme avant</b>. Le prouver par l'absence d'appel au port
 * système ne suffirait pas — on vérifie aussi que le message reçu par l'adaptateur humain porte les
 * quatre mêmes champs qu'auparavant.</p>
 */
class ServiceNotificationsDispatchTest {

    private static final UUID ID = UUID.randomUUID();

    private NotificationSortieRepository outbox;
    private EnvoiNotification humain;
    private NotificationSysteme systeme;
    private ServiceNotifications service;

    @BeforeEach
    void setup() {
        outbox = mock(NotificationSortieRepository.class);
        humain = mock(EnvoiNotification.class);
        systeme = mock(NotificationSysteme.class);
        service = new ServiceNotifications(outbox, humain, systeme, new ObjectMapper(), null);
    }

    private NotificationSortie ligne(TypeNotification type, String destinataire) {
        NotificationSortie n = new NotificationSortie(
                type, "paiement", UUID.randomUUID(), destinataire, "AUTO", "{\"montant\":15000}");
        ReflectionTestUtils.setField(n, "id", ID);
        when(outbox.verrouiller(ID)).thenReturn(Optional.of(n));
        return n;
    }

    // ── 4. Le chemin humain est inchangé ────────────────────────────────────────────────────

    @Test
    @DisplayName("Une notification humaine passe par EnvoiNotification, avec le même message qu'avant")
    void test_notifications_humaines_inchangees() {
        NotificationSortie n = ligne(TypeNotification.PRELEVEMENT_IMMINENT, "USR-1");
        when(humain.envoyer(any())).thenReturn(ResultatEnvoi.reussi("SMS_SIM"));

        assertThat(service.livrerUne(ID)).isTrue();

        ArgumentCaptor<MessageNotification> message = ArgumentCaptor.forClass(MessageNotification.class);
        verify(humain).envoyer(message.capture());
        assertThat(message.getValue().type()).isEqualTo(TypeNotification.PRELEVEMENT_IMMINENT);
        assertThat(message.getValue().destinataireRef()).isEqualTo("USR-1");
        assertThat(message.getValue().canalSouhaite()).isEqualTo("AUTO");
        assertThat(message.getValue().chargeJson()).isEqualTo("{\"montant\":15000}");

        verify(systeme, never()).livrer(anyString());
        assertThat(n.getStatut()).isEqualTo(StatutNotification.ENVOYEE);
        assertThat(n.getCanalLivraison()).isEqualTo("SMS_SIM");
    }

    @Test
    @DisplayName("Les trois types humains restent humains — aucun ne bascule côté système")
    void tousLesTypesHumainsRestentHumains() {
        assertThat(TypeNotification.PRELEVEMENT_IMMINENT.estSysteme()).isFalse();
        assertThat(TypeNotification.PRELEVEMENT_ECHOUE.estSysteme()).isFalse();
        assertThat(TypeNotification.FRAUDE_SUSPECTEE.estSysteme()).isFalse();
        assertThat(TypeNotification.PAIEMENT_NOTIFICATION_LARAVEL.estSysteme()).isTrue();
    }

    @Test
    @DisplayName("Une notification système part par le port dédié, jamais par le canal humain")
    void systemePasseParLePortDedie() {
        NotificationSortie n = ligne(TypeNotification.PAIEMENT_NOTIFICATION_LARAVEL, "LARAVEL-MASANTE");

        assertThat(service.livrerUne(ID)).isTrue();

        verify(systeme).livrer("{\"montant\":15000}");
        verify(humain, never()).envoyer(any());
        assertThat(n.getStatut()).isEqualTo(StatutNotification.ENVOYEE);
        assertThat(n.getCanalLivraison()).isEqualTo(NotificationSysteme.CANAL);
    }

    @Test
    @DisplayName("Échec système : ECHOUEE avec le motif, la politique de rejeu existante s'applique")
    void echecSystemeSuitLaPolitiqueExistante() {
        NotificationSortie n = ligne(TypeNotification.PAIEMENT_NOTIFICATION_LARAVEL, "LARAVEL-MASANTE");
        doThrow(new NotificationSystemeException("Laravel a répondu HTTP 500."))
                .when(systeme).livrer(anyString());

        assertThat(service.livrerUne(ID)).isFalse();

        assertThat(n.getStatut()).isEqualTo(StatutNotification.ECHOUEE);
        assertThat(n.getDetail()).contains("HTTP 500");
        assertThat(n.getTentatives()).isEqualTo(1);
    }

    @Test
    @DisplayName("Une ligne déjà livrée n'est jamais relivrée — garde d'état inchangée")
    void gardeEtatInchangee() {
        NotificationSortie n = ligne(TypeNotification.PAIEMENT_NOTIFICATION_LARAVEL, "LARAVEL-MASANTE");
        n.marquerEnvoyee(NotificationSysteme.CANAL, java.time.Instant.now());

        assertThat(service.livrerUne(ID)).isFalse();

        verify(systeme, never()).livrer(anyString());
        verify(humain, never()).envoyer(any());
    }
}
