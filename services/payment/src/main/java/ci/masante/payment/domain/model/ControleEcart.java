package ci.masante.payment.domain.model;

import ci.masante.payment.domain.integrity.Ecart;
import ci.masante.payment.domain.integrity.Severite;
import ci.masante.payment.domain.integrity.TypeControle;
import ci.masante.payment.domain.integrity.TypeEcart;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.annotations.UuidGenerator;
import org.hibernate.type.SqlTypes;

import java.time.Instant;
import java.util.UUID;

/**
 * Un écart persisté (P5.3b-4). Immuable ; AUCUNE colonne « corrigé » — le contrôle ne corrige jamais
 * (CDC_06 §11). {@code details} = snapshot JSONB rejouable de l'explication.
 */
@Entity
@Table(name = "controle_ecarts")
public class ControleEcart {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "run_id", nullable = false, updatable = false)
    private UUID runId;

    @Enumerated(EnumType.STRING)
    @Column(name = "controle", nullable = false, updatable = false)
    private TypeControle controle;

    @Enumerated(EnumType.STRING)
    @Column(name = "type_ecart", nullable = false, updatable = false)
    private TypeEcart typeEcart;

    @Enumerated(EnumType.STRING)
    @Column(name = "severite", nullable = false, updatable = false)
    private Severite severite;

    @Column(name = "reference", nullable = false, updatable = false)
    private String reference;

    @Column(name = "montant_attendu", updatable = false)
    private Long montantAttendu;

    @Column(name = "montant_constate", updatable = false)
    private Long montantConstate;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "details", nullable = false, updatable = false)
    private String details;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected ControleEcart() {
    }

    public ControleEcart(UUID runId, Ecart ecart, String detailsJson) {
        this.runId = runId;
        this.controle = ecart.controle();
        this.typeEcart = ecart.type();
        this.severite = ecart.severite();
        this.reference = ecart.reference();
        this.montantAttendu = ecart.montantAttendu();
        this.montantConstate = ecart.montantConstate();
        this.details = detailsJson;
    }

    public UUID getId() {
        return id;
    }

    public UUID getRunId() {
        return runId;
    }

    public TypeControle getControle() {
        return controle;
    }

    public TypeEcart getTypeEcart() {
        return typeEcart;
    }

    public Severite getSeverite() {
        return severite;
    }

    public String getReference() {
        return reference;
    }

    public Long getMontantAttendu() {
        return montantAttendu;
    }

    public Long getMontantConstate() {
        return montantConstate;
    }

    public String getDetails() {
        return details;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
