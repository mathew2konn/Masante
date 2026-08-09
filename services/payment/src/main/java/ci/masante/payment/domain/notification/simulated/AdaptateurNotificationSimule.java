package ci.masante.payment.domain.notification.simulated;

import ci.masante.payment.domain.notification.EnvoiNotification;
import ci.masante.payment.domain.notification.MessageNotification;
import ci.masante.payment.domain.notification.ResultatEnvoi;
import org.springframework.stereotype.Component;

/**
 * Envoyeur de notifications SIMULÉ (FT5) : ne livre RIEN pour de vrai — il trace l'envoi de façon
 * déterministe (un destinataire se terminant par {@code FAIL} simule un échec de livraison). Un canal réel
 * (SMS/push/email) = un nouvel adaptateur (secret/API réels) — jamais testé ici → pas « prêt à activer ».
 */
@Component
public class AdaptateurNotificationSimule implements EnvoiNotification {

    public static final String CANAL = "SMS_SIM";

    @Override
    public ResultatEnvoi envoyer(MessageNotification message) {
        String dest = message.destinataireRef() == null ? "" : message.destinataireRef();
        if (dest.endsWith("FAIL")) {
            return ResultatEnvoi.echoue("destinataire injoignable (simulation)");
        }
        return ResultatEnvoi.reussi(CANAL);
    }
}
