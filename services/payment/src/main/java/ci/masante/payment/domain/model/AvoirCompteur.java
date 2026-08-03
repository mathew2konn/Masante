package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.UuidGenerator;

import java.util.UUID;

/** Compteur de numérotation des avoirs, unique par établissement/exercice (miroir de {@link FactureCompteur}). */
@Entity
@Table(name = "avoir_compteurs")
public class AvoirCompteur {

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

    protected AvoirCompteur() {
    }

    public AvoirCompteur(String etablissementRef, int exercice) {
        this.etablissementRef = etablissementRef;
        this.exercice = exercice;
        this.dernier = 0;
    }

    public long prochain() {
        this.dernier += 1;
        return this.dernier;
    }

    public UUID getId() {
        return id;
    }

    public long getDernier() {
        return dernier;
    }
}
