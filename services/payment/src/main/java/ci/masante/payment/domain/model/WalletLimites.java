package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UpdateTimestamp;

import java.time.Instant;
import java.util.UUID;

/**
 * Limites de montant d'un portefeuille (CDC_06 §6.4) : par opération, par jour, par mois.
 * Les valeurs sont des <b>données</b> (jamais codées) surchargeant les défauts de configuration.
 * {@code null} sur une colonne = « utiliser le défaut de configuration ».
 */
@Entity
@Table(name = "wallet_limites")
public class WalletLimites {

    @Id
    @Column(name = "wallet_id", updatable = false, nullable = false)
    private UUID walletId;

    @Column(name = "plafond_operation")
    private Long plafondOperation;

    @Column(name = "plafond_jour")
    private Long plafondJour;

    @Column(name = "plafond_mois")
    private Long plafondMois;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @UpdateTimestamp
    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    protected WalletLimites() {
    }

    public WalletLimites(UUID walletId) {
        this.walletId = walletId;
    }

    public void definir(Long plafondOperation, Long plafondJour, Long plafondMois) {
        this.plafondOperation = plafondOperation;
        this.plafondJour = plafondJour;
        this.plafondMois = plafondMois;
    }

    public UUID getWalletId() {
        return walletId;
    }

    public Long getPlafondOperation() {
        return plafondOperation;
    }

    public Long getPlafondJour() {
        return plafondJour;
    }

    public Long getPlafondMois() {
        return plafondMois;
    }
}
