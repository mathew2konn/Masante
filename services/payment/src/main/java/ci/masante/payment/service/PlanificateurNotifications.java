package ci.masante.payment.service;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

/**
 * Relais de l'outbox de notifications (CDC_03 §8). Intervalle et activation = DONNÉES (config). Idempotent
 * (garde d'état + verrou par ligne). LIVRAISON SIMULÉE (FT5).
 */
@Component
public class PlanificateurNotifications {

    private static final Logger log = LoggerFactory.getLogger(PlanificateurNotifications.class);

    private final ServiceNotifications notifications;
    private final boolean actif;

    public PlanificateurNotifications(ServiceNotifications notifications,
                                      @Value("${masante.payment.notifications.relais.enabled:true}") boolean actif) {
        this.notifications = notifications;
        this.actif = actif;
    }

    @Scheduled(fixedDelayString = "${masante.payment.notifications.relais.intervalle-ms:60000}")
    public void relais() {
        if (!actif) {
            return;
        }
        int n = notifications.envoyerEnAttente();
        if (n > 0) {
            log.info("Relais de notifications : {} livrée(s) (simulé)", n);
        }
    }
}
