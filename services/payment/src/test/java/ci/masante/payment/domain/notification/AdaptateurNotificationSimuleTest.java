package ci.masante.payment.domain.notification;

import ci.masante.payment.domain.notification.simulated.AdaptateurNotificationSimule;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;

/** Adaptateur de notification SIMULÉ (P5.4c) — déterministe : destinataire en « FAIL » → échec. */
class AdaptateurNotificationSimuleTest {

    private final AdaptateurNotificationSimule adaptateur = new AdaptateurNotificationSimule();

    @Test
    @DisplayName("Livraison réussie pour un destinataire normal")
    void succes() {
        ResultatEnvoi r = adaptateur.envoyer(new MessageNotification(
                TypeNotification.PRELEVEMENT_IMMINENT, "USR-1", "AUTO", "{}"));
        assertThat(r.reussi()).isTrue();
        assertThat(r.canal()).isEqualTo(AdaptateurNotificationSimule.CANAL);
    }

    @Test
    @DisplayName("Échec déterministe pour un destinataire se terminant par FAIL")
    void echec() {
        ResultatEnvoi r = adaptateur.envoyer(new MessageNotification(
                TypeNotification.PRELEVEMENT_ECHOUE, "USR-FAIL", "AUTO", "{}"));
        assertThat(r.reussi()).isFalse();
        assertThat(r.detail()).isNotBlank();
    }
}
