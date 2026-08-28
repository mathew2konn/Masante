package ci.masante.payment.domain.gateway.geniuspay;

/**
 * Erreur renvoyée par GeniusPay, portant son {@code error.code} ({@code INVALID_API_KEY},
 * {@code MERCHANT_INACTIVE}, {@code VALIDATION_ERROR}…).
 *
 * <p><b>Aucun de ces codes n'est ré-essayable sur une initiation</b> (§4.3). L'exception est donc
 * typée pour être distinguée d'une panne réseau — qui, elle, laisse l'incertitude — et non pour
 * ouvrir un chemin de rejeu.</p>
 */
public class GeniusPayException extends RuntimeException {

    private final String code;
    private final int statutHttp;

    public GeniusPayException(String code, int statutHttp, String message) {
        super(message);
        this.code = code;
        this.statutHttp = statutHttp;
    }

    public String getCode() {
        return code;
    }

    public int getStatutHttp() {
        return statutHttp;
    }
}
