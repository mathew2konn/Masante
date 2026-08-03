package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.UuidGenerator;

import java.util.UUID;

/**
 * Compteur de numérotation des factures, unique par établissement et par exercice (CDC_06 §7.4).
 * Incrémenté sous verrou pessimiste pour garantir une séquence sans trou ni collision.
 */
@Entity
@Table(name = "facture_compteurs")
public class FactureCompteur {

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

    protected FactureCompteur() {
    }

    public FactureCompteur(String etablissementRef, int exercice) {
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
