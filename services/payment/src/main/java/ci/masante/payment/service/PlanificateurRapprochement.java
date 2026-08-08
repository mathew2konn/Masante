package ci.masante.payment.service;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

import java.time.LocalDate;
import java.time.ZoneOffset;

/**
 * Déclenche AUTOMATIQUEMENT le rapprochement quotidien « factures ↔ reversements » (CDC_06 §11
 * « rapprochement automatique quotidien »). Rapproche la journée de la VEILLE (journée comptable close).
 * L'exécution manuelle reste possible via {@code POST /api/v1/settlement-reconciliations/run} (preuve
 * G2/G4).
 *
 * <p>Lecture seule sur les données financières (comme {@link ServiceRapprochementReversement}) : la
 * planification est sans risque. Horaire (cron) et activation = DONNÉES (config), jamais codés.</p>
 */
@Component
public class PlanificateurRapprochement {

    private static final Logger log = LoggerFactory.getLogger(PlanificateurRapprochement.class);

    private final ServiceRapprochementReversement service;
    private final boolean actif;

    public PlanificateurRapprochement(
            ServiceRapprochementReversement service,
            @Value("${masante.payment.reversement.rapprochement.planification.enabled:true}") boolean actif) {
        this.service = service;
        this.actif = actif;
    }

    @Scheduled(cron = "${masante.payment.reversement.rapprochement.planification.cron:0 0 2 * * *}", zone = "UTC")
    public void quotidien() {
        if (!actif) {
            return;
        }
        LocalDate veille = LocalDate.now(ZoneOffset.UTC).minusDays(1);
        var rapport = service.executer(veille);
        log.info("Rapprochement factures↔reversements — journée {} : {} ({} écart(s))",
                veille, rapport.getStatut(), rapport.getNbEcarts());
    }
}
