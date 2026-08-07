package ci.masante.payment.service;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

import java.time.LocalDate;
import java.time.ZoneOffset;

/**
 * Jobs planifiés du domaine carte (CDC_06 §5/§6.3, ADR-015). Horaires et activation = DONNÉES (config),
 * jamais codés. Chaque job est idempotent et sans effet de bord métier hors de son objet.
 *
 * <ul>
 *   <li><b>Expiration des défis</b> (~1 min) : EN_ATTENTE_CLIENT échu → EXPIREE (redirection/défi abandonné).</li>
 *   <li><b>Expiration des autorisations</b> (quotidien) : AUTORISEE non capturée échue → EXPIREE (capture différée).</li>
 *   <li><b>Réconciliation</b> (quotidien) : confronte le registre local à la vérité PSP pour la veille.</li>
 * </ul>
 */
@Component
public class PlanificateurCarte {

    private static final Logger log = LoggerFactory.getLogger(PlanificateurCarte.class);

    private final ServiceCarte cartes;
    private final ServiceReconciliationCarte reconciliation;
    private final boolean actif;

    public PlanificateurCarte(ServiceCarte cartes,
                              ServiceReconciliationCarte reconciliation,
                              @Value("${masante.payment.cartes.planification.enabled:true}") boolean actif) {
        this.cartes = cartes;
        this.reconciliation = reconciliation;
        this.actif = actif;
    }

    @Scheduled(fixedDelayString = "${masante.payment.cartes.planification.expiration-defi-ms:60000}")
    public void expirationDefis() {
        if (!actif) {
            return;
        }
        int n = cartes.expirerDefisEchus();
        if (n > 0) {
            log.info("Expiration de défis carte échus : {} transaction(s) → EXPIREE", n);
        }
    }

    @Scheduled(cron = "${masante.payment.cartes.planification.expiration-autorisation-cron:0 15 1 * * *}", zone = "UTC")
    public void expirationAutorisations() {
        if (!actif) {
            return;
        }
        int n = cartes.expirerAutorisationsEchues();
        if (n > 0) {
            log.info("Expiration d'autorisations carte non capturées : {} transaction(s) → EXPIREE", n);
        }
    }

    @Scheduled(cron = "${masante.payment.cartes.planification.reconciliation-cron:0 45 1 * * *}", zone = "UTC")
    public void reconciliationQuotidienne() {
        if (!actif) {
            return;
        }
        LocalDate veille = LocalDate.now(ZoneOffset.UTC).minusDays(1);
        var rapports = reconciliation.executerJournee(veille);
        int total = rapports.stream().mapToInt(r -> r.getNbEcarts()).sum();
        log.info("Réconciliation carte — journée {} : {} rapport(s), {} écart(s)", veille, rapports.size(), total);
    }
}
