package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.util.UUID;

/**
 * Jambe (ligne) du grand livre reversement, rattachée à une écriture. Append-only. Σ débit = Σ crédit
 * par {@code ecritureId} garanti à l'écriture (ReglesEcritureReversement) et vérifié en G2.
 */
@Entity
@Table(name = "reversement_grand_livre_ligne")
public class LigneGrandLivre {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "ecriture_id", nullable = false, updatable = false)
    private UUID ecritureId;

    @Column(name = "sequence", nullable = false, updatable = false)
    private short sequence;

    @Enumerated(EnumType.STRING)
    @Column(name = "compte", nullable = false, updatable = false)
    private CompteReversement compte;

    @Enumerated(EnumType.STRING)
    @Column(name = "sens", nullable = false, updatable = false)
    private SensEcriture sens;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "libelle", nullable = false, updatable = false)
    private String libelle;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected LigneGrandLivre() {
    }

    public LigneGrandLivre(UUID ecritureId, short sequence, CompteReversement compte, SensEcriture sens,
                           long montant, String libelle) {
        this.ecritureId = ecritureId;
        this.sequence = sequence;
        this.compte = compte;
        this.sens = sens;
        this.montant = montant;
        this.devise = "XOF";
        this.libelle = libelle;
    }

    public UUID getId() {
        return id;
    }

    public UUID getEcritureId() {
        return ecritureId;
    }

    public short getSequence() {
        return sequence;
    }

    public CompteReversement getCompte() {
        return compte;
    }

    public SensEcriture getSens() {
        return sens;
    }

    public long getMontant() {
        return montant;
    }

    public String getDevise() {
        return devise;
    }

    public String getLibelle() {
        return libelle;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
