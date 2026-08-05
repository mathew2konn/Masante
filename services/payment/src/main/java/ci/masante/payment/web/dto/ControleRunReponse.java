package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.ControleRun;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/** Verdict synthétique d'un run de contrôle d'intégrité. */
public record ControleRunReponse(UUID id, LocalDate journee, Instant arreteA, String statut,
                                 int nbControles, int nbEcarts, long dureeMs, Instant executeA) {

    public static ControleRunReponse de(ControleRun r) {
        return new ControleRunReponse(r.getId(), r.getJournee(), r.getArreteA(), r.getStatut().name(),
                r.getNbControles(), r.getNbEcarts(), r.getDureeMs(), r.getExecuteA());
    }
}
