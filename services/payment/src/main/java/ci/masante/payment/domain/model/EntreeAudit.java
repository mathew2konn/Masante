package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.util.UUID;

/**
 * Entrée du journal d'audit immuable, append-only à hachage chaîné (CDC_06 §9.7).
 *
 * <p>{@code hash = SHA-256(sequence | evenement | refType | refId | payload | previousHash)}. La
 * chaîne rend toute altération ou suppression détectable. Aucune mise à jour n'est jamais effectuée
 * sur cette table (pas de setter).</p>
 */
@Entity
@Table(name = "audit_entries")
public class EntreeAudit {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "sequence", nullable = false, updatable = false)
    private long sequence;

    @Column(name = "evenement", nullable = false, updatable = false)
    private String evenement;

    @Column(name = "ref_type", nullable = false, updatable = false)
    private String refType;

    @Column(name = "ref_id", nullable = false, updatable = false)
    private String refId;

    @Column(name = "payload", nullable = false, updatable = false)
    private String payload;

    @Column(name = "previous_hash", nullable = false, updatable = false)
    private String previousHash;

    @Column(name = "hash", nullable = false, updatable = false)
    private String hash;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected EntreeAudit() {
    }

    public EntreeAudit(long sequence, String evenement, String refType, String refId,
                       String payload, String previousHash, String hash) {
        this.sequence = sequence;
        this.evenement = evenement;
        this.refType = refType;
        this.refId = refId;
        this.payload = payload;
        this.previousHash = previousHash;
        this.hash = hash;
    }

    public UUID getId() {
        return id;
    }

    public long getSequence() {
        return sequence;
    }

    public String getEvenement() {
        return evenement;
    }

    public String getRefType() {
        return refType;
    }

    public String getRefId() {
        return refId;
    }

    public String getPayload() {
        return payload;
    }

    public String getPreviousHash() {
        return previousHash;
    }

    public String getHash() {
        return hash;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
