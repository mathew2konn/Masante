package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.UuidGenerator;

import java.util.UUID;

/**
 * Compteur de numérotation des relevés de reversement, unique par établissement/exercice (CDC_06 §11).
 * Incrémenté sous verrou pessimiste (séquence sans trou). Pattern identique à {@link FactureCompteur}.
 */
@Entity
@Table(name = "reversement_compteur")
public class ReversementCompteur {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "etablissement_ref", nullable = false, updatable = false)
    private String etablissementRef;

    @Column(name = "exercice", nullable = false, updatable = false)
    private int exercice;

    @Column(name = "dernier", nullable = false)
    private long dernier;

    protected ReversementCompteur() {
    }

    public ReversementCompteur(String etablissementRef, int exercice) {
        this.etablissementRef = etablissementRef;
        this.exercice = exercice;
        this.dernier = 0;
    }

    /** Réserve et renvoie le prochain numéro de séquence. */
    public long prochain() {
        this.dernier += 1;
        return this.dernier;
    }

    public UUID getId() {
        return id;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public int getExercice() {
        return exercice;
    }

    public long getDernier() {
        return dernier;
    }
}
