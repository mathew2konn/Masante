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
 * Une transition d'état persistée (CDC_06 §4.2 : « toute transition horodatée et persistée »).
 * {@code statutDe == null} marque la création (INITIATED).
 */
@Entity
@Table(name = "payment_transitions")
public class TransitionPaiement {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "payment_id", nullable = false, updatable = false)
    private UUID paymentId;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut_de", updatable = false)
    private PaiementStatut statutDe;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut_vers", nullable = false, updatable = false)
    private PaiementStatut statutVers;

    @Column(name = "raison", updatable = false)
    private String raison;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected TransitionPaiement() {
    }

    public TransitionPaiement(UUID paymentId, PaiementStatut statutDe, PaiementStatut statutVers, String raison) {
        this.paymentId = paymentId;
        this.statutDe = statutDe;
        this.statutVers = statutVers;
        this.raison = raison;
    }

    public UUID getId() {
        return id;
    }

    public UUID getPaymentId() {
        return paymentId;
    }

    public PaiementStatut getStatutDe() {
        return statutDe;
    }

    public PaiementStatut getStatutVers() {
        return statutVers;
    }

    public String getRaison() {
        return raison;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
