package ci.masante.payment.service;

import ci.masante.payment.service.ServiceRoutageFraude.RapportRoutage;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

import java.time.LocalDate;
import java.time.ZoneOffset;

/**
 * Déclenche AUTOMATIQUEMENT le routage quotidien des alertes de fraude IA (CDC_05 §6.9 « détection de
 * comportements anormaux »). Évalue la journée de la VEILLE (close). L'exécution manuelle reste possible
 * via {@code POST /api/v1/fraud-alertes/scan} (preuve G2/G4).
 *
 * <p>Détection seule : aucune action automatique. Si le fraud-detection-service est injoignable, le run
 * échoue proprement (log) sans planter le planificateur ni toucher le paiement. Horaire et activation =
 * DONNÉES (config), jamais codés.</p>
 */
@Component
public class PlanificateurRoutageFraude {

    private static final Logger log = LoggerFactory.getLogger(PlanificateurRoutageFraude.class);

    private final ServiceRoutageFraude service;
    private final boolean actif;

    public PlanificateurRoutageFraude(
            ServiceRoutageFraude service,
            @Value("${masante.payment.fraude.planification.enabled:true}") boolean actif) {
        this.service = service;
        this.actif = actif;
    }

    @Scheduled(cron = "${masante.payment.fraude.planification.cron:0 30 2 * * *}", zone = "UTC")
    public void quotidien() {
        if (!actif) {
            return;
        }
        LocalDate veille = LocalDate.now(ZoneOffset.UTC).minusDays(1);
        try {
            RapportRoutage r = service.executer(veille);
            log.info("Routage fraude — journée {} : {} évaluée(s), {} nouvelle(s) alerte(s), {} notifiée(s)",
                    veille, r.nbEvaluees(), r.nbNouvelles(), r.nbNotifiees());
        } catch (FraudeInjoignableException e) {
            log.warn("Routage fraude — journée {} : fraud-detection-service injoignable, run reporté ({})",
                    veille, e.getMessage());
        }
    }
}
