package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import jakarta.persistence.Version;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UpdateTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.util.UUID;

/**
 * Portefeuille électronique (CDC_06 §6). Le <b>solde n'est pas un champ</b> : il est la somme des
 * écritures ({@code wallet_entries}) — jamais modifié directement (§6.3).
 */
@Entity
@Table(name = "wallets")
public class Wallet {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "owner_ref", nullable = false, updatable = false)
    private String ownerRef;

    @Enumerated(EnumType.STRING)
    @Column(name = "owner_type", nullable = false, updatable = false)
    private OwnerTypeWallet ownerType;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private WalletStatut statut;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @UpdateTimestamp
    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    protected Wallet() {
    }

    public Wallet(String ownerRef, OwnerTypeWallet ownerType, String devise) {
        this.ownerRef = ownerRef;
        this.ownerType = ownerType;
        this.devise = devise;
        this.statut = WalletStatut.ACTIF;
    }

    public UUID getId() {
        return id;
    }

    public String getOwnerRef() {
        return ownerRef;
    }

    public OwnerTypeWallet getOwnerType() {
        return ownerType;
    }

    public String getDevise() {
        return devise;
    }

    public WalletStatut getStatut() {
        return statut;
    }

    public void setStatut(WalletStatut statut) {
        this.statut = statut;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
