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
 * Opération du wallet (CDC_06 §6.2) : regroupe les DEUX écritures. Sa clé d'idempotence garantit
 * qu'une même demande ne s'applique qu'une fois (§9.6).
 */
@Entity
@Table(name = "wallet_operations")
public class WalletOperation {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "idempotency_key", nullable = false, updatable = false)
    private String idempotencyKey;

    @Enumerated(EnumType.STRING)
    @Column(name = "type", nullable = false, updatable = false)
    private TypeOperationWallet type;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "source_wallet_id", updatable = false)
    private UUID sourceWalletId;

    @Column(name = "dest_wallet_id", updatable = false)
    private UUID destWalletId;

    @Column(name = "reference", updatable = false)
    private String reference;

    @Column(name = "libelle", updatable = false)
    private String libelle;

    @Column(name = "facture_id", updatable = false)
    private UUID factureId;

    /** Signature d'opération (§6.4) — « prête à activer » (RSA-SHA256). null si signature désactivée. */
    @Column(name = "signature")
    private String signature;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected WalletOperation() {
    }

    public WalletOperation(String idempotencyKey, TypeOperationWallet type, long montant,
                           UUID sourceWalletId, UUID destWalletId, String reference,
                           String libelle, UUID factureId) {
        this.idempotencyKey = idempotencyKey;
        this.type = type;
        this.montant = montant;
        this.sourceWalletId = sourceWalletId;
        this.destWalletId = destWalletId;
        this.reference = reference;
        this.libelle = libelle;
        this.factureId = factureId;
    }

    public UUID getId() {
        return id;
    }

    public String getIdempotencyKey() {
        return idempotencyKey;
    }

    public TypeOperationWallet getType() {
        return type;
    }

    public long getMontant() {
        return montant;
    }

    public UUID getSourceWalletId() {
        return sourceWalletId;
    }

    public UUID getDestWalletId() {
        return destWalletId;
    }

    public String getReference() {
        return reference;
    }

    public String getLibelle() {
        return libelle;
    }

    public UUID getFactureId() {
        return factureId;
    }

    public String getSignature() {
        return signature;
    }

    /** Appose la signature d'opération après enregistrement (§6.4). */
    public void apposerSignature(String signature) {
        this.signature = signature;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
