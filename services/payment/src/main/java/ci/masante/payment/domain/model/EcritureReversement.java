package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/**
 * En-tête d'une écriture du grand livre reversement (partie double). {@code ecritureId} généré côté
 * application. Append-only : une écriture ne se modifie ni ne se supprime ; une correction est une
 * écriture {@code EXTOURNE} qui la référence.
 */
@Entity
@Table(name = "reversement_ecriture")
public class EcritureReversement {

    @Id
    @Column(name = "ecriture_id", updatable = false, nullable = false)
    private UUID ecritureId;

    @Column(name = "releve_id", nullable = false, updatable = false)
    private UUID releveId;

    @Enumerated(EnumType.STRING)
    @Column(name = "type_ecriture", nullable = false, updatable = false)
    private TypeEcriture typeEcriture;

    @Column(name = "date_comptable", nullable = false, updatable = false)
    private LocalDate dateComptable;

    @Column(name = "ecriture_extournee_id", updatable = false)
    private UUID ecritureExtourneeId;

    @Column(name = "cree_par", nullable = false, updatable = false)
    private String creePar;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    protected EcritureReversement() {
    }

    public EcritureReversement(UUID ecritureId, UUID releveId, TypeEcriture typeEcriture,
                               LocalDate dateComptable, UUID ecritureExtourneeId, String creePar) {
        this.ecritureId = ecritureId;
        this.releveId = releveId;
        this.typeEcriture = typeEcriture;
        this.dateComptable = dateComptable;
        this.ecritureExtourneeId = ecritureExtourneeId;
        this.creePar = creePar;
    }

    public UUID getEcritureId() {
        return ecritureId;
    }

    public UUID getReleveId() {
        return releveId;
    }

    public TypeEcriture getTypeEcriture() {
        return typeEcriture;
    }

    public LocalDate getDateComptable() {
        return dateComptable;
    }

    public UUID getEcritureExtourneeId() {
        return ecritureExtourneeId;
    }

    public String getCreePar() {
        return creePar;
    }

    public Instant getCreeLe() {
        return creeLe;
    }
}
