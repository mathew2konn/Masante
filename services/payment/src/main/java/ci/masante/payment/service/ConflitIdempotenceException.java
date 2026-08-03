package ci.masante.payment.service;

/** Un traitement est déjà en cours pour cette clé d'idempotence (verrou Redis détenu). → HTTP 409. */
public class ConflitIdempotenceException extends RuntimeException {

    public ConflitIdempotenceException(String cle) {
        super("Un paiement est déjà en cours de traitement pour cette clé d'idempotence : " + cle);
    }
}
