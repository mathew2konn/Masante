package ci.masante.payment.service;

import ci.masante.payment.domain.model.AlerteFraudeIa;
import ci.masante.payment.domain.model.StatutAlerteFraudeIa;
import ci.masante.payment.repository.AlerteFraudeIaRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

/**
 * Consultation et revue des alertes de fraude IA (B1). LECTURE + marquage « revue » par un
 * {@code ADMIN_FINANCE}. Aucune action métier : marquer une alerte revue ne gèle/corrige rien
 * (détection seule, ADR-017) — c'est la trace qu'un humain l'a traitée.
 */
@Service
public class ServiceAlertesFraude {

    private final AlerteFraudeIaRepository alertes;

    public ServiceAlertesFraude(AlerteFraudeIaRepository alertes) {
        this.alertes = alertes;
    }

    @Transactional(readOnly = true)
    public List<AlerteFraudeIa> lister(StatutAlerteFraudeIa statut) {
        return statut == null
                ? alertes.findTop200ByOrderByCreatedAtDesc()
                : alertes.findTop200ByStatutOrderByCreatedAtDesc(statut);
    }

    @Transactional(readOnly = true)
    public AlerteFraudeIa trouver(UUID id) {
        return alertes.findById(id).orElseThrow(() -> new AlerteFraudeIntrouvableException(id.toString()));
    }

    @Transactional
    public AlerteFraudeIa revue(UUID id, String par) {
        AlerteFraudeIa alerte = alertes.findById(id)
                .orElseThrow(() -> new AlerteFraudeIntrouvableException(id.toString()));
        alerte.marquerRevue(par, Instant.now());
        return alertes.save(alerte);
    }
}
