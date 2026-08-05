package ci.masante.payment.repository.projection;

import java.util.UUID;

/** Cashback d'origine et clawback cumulé pour une opération source (réversibilité). */
public interface ClawbackSourceProj {
    UUID getSourceId();

    long getCashback();

    long getClawback();
}
