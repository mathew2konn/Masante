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
 * Écriture du grand livre (CDC_06 §6.3), immuable. {@code montant} signé : +crédit / −débit.
 * Chaque opération en produit deux, dont la somme vaut 0 (double écriture).
 */
@Entity
@Table(name = "wallet_entries")
public class WalletEntry {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "operation_id", nullable = false, updatable = false)
    private UUID operationId;

    @Column(name = "wallet_id", nullable = false, updatable = false)
    private UUID walletId;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected WalletEntry() {
    }

    public WalletEntry(UUID operationId, UUID walletId, long montant) {
        this.operationId = operationId;
        this.walletId = walletId;
        this.montant = montant;
    }

    public UUID getId() {
        return id;
    }

    public UUID getOperationId() {
        return operationId;
    }

    public UUID getWalletId() {
        return walletId;
    }

    public long getMontant() {
        return montant;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
