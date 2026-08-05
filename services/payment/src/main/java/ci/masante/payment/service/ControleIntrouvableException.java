package ci.masante.payment.service;

import java.util.UUID;

/** Run de contrôle d'intégrité inexistant. → HTTP 404. */
public class ControleIntrouvableException extends RuntimeException {

    public ControleIntrouvableException(UUID runId) {
        super("Run de contrôle introuvable : " + runId);
    }
}
