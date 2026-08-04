package ci.masante.payment.domain.fraud;

import java.util.UUID;

/**
 * Opération bloquée pour suspicion de fraude (palier GEL, §6.4). Porte le résultat détaillé pour le
 * traitement interne (gel + alerte + audit), mais son <b>message reste générique</b> : ni score ni
 * motifs ne fuient vers le client (anti-divulgation).
 */
public class FraudSuspecteeException extends RuntimeException {

    private final transient UUID walletId;
    private final transient long montantTente;
    private final transient ResultatFraude resultat;

    public FraudSuspecteeException(UUID walletId, long montantTente, ResultatFraude resultat) {
        super("Opération refusée pour raison de sécurité.");
        this.walletId = walletId;
        this.montantTente = montantTente;
        this.resultat = resultat;
    }

    public UUID walletId() {
        return walletId;
    }

    public long montantTente() {
        return montantTente;
    }

    public ResultatFraude resultat() {
        return resultat;
    }
}
