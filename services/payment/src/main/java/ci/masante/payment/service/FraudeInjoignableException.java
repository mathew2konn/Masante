package ci.masante.payment.service;

/**
 * Le fraud-detection-service est injoignable ou répond mal → 502. Dégradation HONNÊTE : le run de routage
 * échoue proprement, aucune alerte n'est inventée ni persistée (détection seule, jamais de faux positif
 * fabriqué). Le core paiement n'est pas affecté (le scoring est hors du chemin transactionnel de paiement).
 */
public class FraudeInjoignableException extends RuntimeException {
    public FraudeInjoignableException(String message, Throwable cause) {
        super(message, cause);
    }
}
