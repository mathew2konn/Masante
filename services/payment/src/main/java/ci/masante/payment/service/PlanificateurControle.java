package ci.masante.payment.service;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

import java.time.LocalDate;
import java.time.ZoneOffset;

/**
 * Déclenche AUTOMATIQUEMENT le contrôle d'intégrité quotidien (CDC_06 §6.3 « rapprochement quotidien
 * automatique »). Contrôle la journée de la VEILLE (journée comptable close). L'exécution manuelle sur
 * une journée choisie reste possible via {@code POST /api/v1/integrity-checks/run} (preuve G2/G4).
 *
 * <p>Read-only sur les données financières (comme {@link ServiceControleIntegrite}) : la planification
 * est donc sans risque. L'horaire (cron) et l'activation sont des DONNÉES (config), jamais codés.</p>
 */
@Component
public class PlanificateurControle {

    private static final Logger log = LoggerFactory.getLogger(PlanificateurControle.class);

    private final ServiceControleIntegrite service;
    private final boolean actif;

    public PlanificateurControle(ServiceControleIntegrite service,
                                 @Value("${masante.payment.integrite.planification.enabled:true}") boolean actif) {
        this.service = service;
        this.actif = actif;
    }

    @Scheduled(cron = "${masante.payment.integrite.planification.cron:0 30 1 * * *}", zone = "UTC")
    public void quotidien() {
        if (!actif) {
            return;
        }
        LocalDate veille = LocalDate.now(ZoneOffset.UTC).minusDays(1);
        var run = service.executer(veille);
        log.info("Contrôle d'intégrité quotidien — journée {} : {} ({} écart(s))",
                veille, run.getStatut(), run.getNbEcarts());
    }
}
