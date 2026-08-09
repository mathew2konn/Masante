package ci.masante.payment.repository.projection;

import java.time.Instant;
import java.util.UUID;

/**
 * Champs de base d'une facture, socle des SIGNAUX de facturation exposés à la détection de fraude
 * (extraction réelle, incrément A). LECTURE SEULE : projection, aucune décision. Le patient peut être
 * {@code null} (facture sans patient identifié) → les signaux keyés patient valent alors 0.
 */
public interface SignauxFactureProj {
    UUID getId();

    String getReference();

    String getEtablissementRef();

    String getPatientRef();

    long getMontantTtc();

    long getMontantCouvert();

    long getResteAPayer();

    Instant getCreatedAt();
}
