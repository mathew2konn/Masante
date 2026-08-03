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
 * Transaction de paiement (CDC_06 §4.3). FCFA = entier. Le statut n'évolue que via la machine à
 * états (§4.2), consignée dans {@code payment_transitions}, et chaque étape est auditée.
 */
@Entity
@Table(name = "payments")
public class Paiement {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "idempotency_key", nullable = false, updatable = false)
    private String idempotencyKey;

    @Column(name = "correlation_id", updatable = false)
    private String correlationId;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "canal", nullable = false, updatable = false)
    private String canal;

    @Enumerated(EnumType.STRING)
    @Column(name = "objet", nullable = false, updatable = false)
    private ObjetPaiement objet;

    @Column(name = "telephone_masque", updatable = false)
    private String telephoneMasque;

    @Column(name = "etablissement_ref", updatable = false)
    private String etablissementRef;

    @Column(name = "patient_ref", updatable = false)
    private String patientRef;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private PaiementStatut statut;

    @Column(name = "provider_ref")
    private String providerRef;

    /** Facture soldée par ce paiement (null si paiement hors facture). Renseigné après confirmation. */
    @Column(name = "facture_id")
    private UUID factureId;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @UpdateTimestamp
    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    @Column(name = "confirmed_at")
    private Instant confirmedAt;

    protected Paiement() {
    }

    public Paiement(String idempotencyKey, String correlationId, long montant, String devise,
                    String canal, ObjetPaiement objet, String telephoneMasque,
                    String etablissementRef, String patientRef) {
        this.idempotencyKey = idempotencyKey;
        this.correlationId = correlationId;
        this.montant = montant;
        this.devise = devise;
        this.canal = canal;
        this.objet = objet;
        this.telephoneMasque = telephoneMasque;
        this.etablissementRef = etablissementRef;
        this.patientRef = patientRef;
        this.statut = PaiementStatut.INITIATED;
    }

    public UUID getId() {
        return id;
    }

    public String getIdempotencyKey() {
        return idempotencyKey;
    }

    public String getCorrelationId() {
        return correlationId;
    }

    public long getMontant() {
        return montant;
    }

    public String getDevise() {
        return devise;
    }

    public String getCanal() {
        return canal;
    }

    public ObjetPaiement getObjet() {
        return objet;
    }

    public String getTelephoneMasque() {
        return telephoneMasque;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public String getPatientRef() {
        return patientRef;
    }

    public PaiementStatut getStatut() {
        return statut;
    }

    public void setStatut(PaiementStatut statut) {
        this.statut = statut;
    }

    public String getProviderRef() {
        return providerRef;
    }

    public void setProviderRef(String providerRef) {
        this.providerRef = providerRef;
    }

    public UUID getFactureId() {
        return factureId;
    }

    public void setFactureId(UUID factureId) {
        this.factureId = factureId;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }

    public Instant getUpdatedAt() {
        return updatedAt;
    }

    public Instant getConfirmedAt() {
        return confirmedAt;
    }

    public void setConfirmedAt(Instant confirmedAt) {
        this.confirmedAt = confirmedAt;
    }
}
