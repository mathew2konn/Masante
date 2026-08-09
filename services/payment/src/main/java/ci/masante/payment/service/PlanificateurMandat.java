package ci.masante.payment.service;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

import java.time.LocalDate;
import java.time.ZoneOffset;

/**
 * Jobs planifiés des mandats récurrents (CDC_06 §5.4). Horaires et activation = DONNÉES (config).
 * Chaque job est idempotent (préavis posé une fois, échéance exécutée une fois grâce aux garde-fous du service).
 *
 * <ul>
 *   <li><b>Préavis</b> (quotidien) : pose les préavis des échéances qui approchent (livraison différée).</li>
 *   <li><b>Exécution</b> (quotidien) : débite les échéances dues (MIT simulé).</li>
 *   <li><b>Expiration</b> (quotidien) : clôt les mandats dont la date de fin est dépassée.</li>
 * </ul>
 */
@Component
public class PlanificateurMandat {

    private static final Logger log = LoggerFactory.getLogger(PlanificateurMandat.class);

    private final ServiceMandat mandats;
    private final boolean actif;

    public PlanificateurMandat(ServiceMandat mandats,
                               @Value("${masante.payment.mandats.planification.enabled:true}") boolean actif) {
        this.mandats = mandats;
        this.actif = actif;
    }

    @Scheduled(cron = "${masante.payment.mandats.planification.preavis-cron:0 0 6 * * *}", zone = "UTC")
    public void preavisQuotidien() {
        if (!actif) {
            return;
        }
        int n = mandats.poserPreavisDus(LocalDate.now(ZoneOffset.UTC));
        if (n > 0) {
            log.info("Préavis de prélèvement posés : {}", n);
        }
    }

    @Scheduled(cron = "${masante.payment.mandats.planification.execution-cron:0 30 6 * * *}", zone = "UTC")
    public void executionQuotidienne() {
        if (!actif) {
            return;
        }
        var resume = mandats.executerEcheancesDues(LocalDate.now(ZoneOffset.UTC));
        if (resume.total() > 0) {
            log.info("Exécution des échéances de mandats — {} due(s) : {} exécutée(s), {} échouée(s), {} ignorée(s)",
                    resume.total(), resume.executees(), resume.echouees(), resume.ignorees());
        }
    }

    @Scheduled(cron = "${masante.payment.mandats.planification.expiration-cron:0 45 6 * * *}", zone = "UTC")
    public void expirationQuotidienne() {
        if (!actif) {
            return;
        }
        int n = mandats.expirerMandatsEchus(LocalDate.now(ZoneOffset.UTC));
        if (n > 0) {
            log.info("Mandats expirés (date de fin dépassée) : {}", n);
        }
    }
}
