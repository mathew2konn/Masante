package ci.masante.payment.service;

/**
 * Opération carte invalide dans l'état courant (remboursement > capturé, devise incohérente, capture
 * refusée…). → 422 mappé en Phase 6. Le message reste générique côté client (anti-fuite).
 */
public class OperationCarteInvalideException extends RuntimeException {

    public OperationCarteInvalideException(String message) {
        super(message);
    }
}
