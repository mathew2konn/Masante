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
 * Ligne de relevé de reversement : snapshot immuable d'une pièce imputée (facture ou remboursement).
 * Append-only ; seule {@code releveActif} est mutable (basculée à FALSE à l'annulation du relevé, pour
 * libérer la pièce — support des index partiels I1). Aucun autre mutateur.
 */
@Entity
@Table(name = "reversement_releve_lignes")
public class LigneReversement {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "releve_id", nullable = false, updatable = false)
    private UUID releveId;

    @Enumerated(EnumType.STRING)
    @Column(name = "type_ligne", nullable = false, updatable = false)
    private TypeLigneReversement typeLigne;

    @Column(name = "facture_id", updatable = false)
    private UUID factureId;

    @Column(name = "remboursement_id", updatable = false)
    private UUID remboursementId;

    @Column(name = "piece_reference", nullable = false, updatable = false)
    private String pieceReference;

    @Column(name = "piece_datee_a", nullable = false, updatable = false)
    private Instant pieceDateeA;

    @Column(name = "montant_regle_impute", nullable = false, updatable = false)
    private long montantRegleImpute;

    @Column(name = "montant_commission_ligne", nullable = false, updatable = false)
    private long montantCommissionLigne;

    @Column(name = "montant_rembourse_impute", nullable = false, updatable = false)
    private long montantRembourseImpute;

    @Column(name = "montant_net_ligne", nullable = false, updatable = false)
    private long montantNetLigne;

    @Column(name = "releve_actif", nullable = false)
    private boolean releveActif = true;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected LigneReversement() {
    }

    public LigneReversement(UUID releveId, TypeLigneReversement typeLigne, UUID factureId, UUID remboursementId,
                            String pieceReference, Instant pieceDateeA, long montantRegleImpute,
                            long montantCommissionLigne, long montantRembourseImpute, long montantNetLigne) {
        this.releveId = releveId;
        this.typeLigne = typeLigne;
        this.factureId = factureId;
        this.remboursementId = remboursementId;
        this.pieceReference = pieceReference;
        this.pieceDateeA = pieceDateeA;
        this.montantRegleImpute = montantRegleImpute;
        this.montantCommissionLigne = montantCommissionLigne;
        this.montantRembourseImpute = montantRembourseImpute;
        this.montantNetLigne = montantNetLigne;
    }

    /** Désactive la ligne (annulation du relevé) → libère la pièce pour un futur relevé. */
    public void desactiver() {
        this.releveActif = false;
    }

    public UUID getId() {
        return id;
    }

    public UUID getReleveId() {
        return releveId;
    }

    public TypeLigneReversement getTypeLigne() {
        return typeLigne;
    }

    public UUID getFactureId() {
        return factureId;
    }

    public UUID getRemboursementId() {
        return remboursementId;
    }

    public String getPieceReference() {
        return pieceReference;
    }

    public Instant getPieceDateeA() {
        return pieceDateeA;
    }

    public long getMontantRegleImpute() {
        return montantRegleImpute;
    }

    public long getMontantCommissionLigne() {
        return montantCommissionLigne;
    }

    public long getMontantRembourseImpute() {
        return montantRembourseImpute;
    }

    public long getMontantNetLigne() {
        return montantNetLigne;
    }

    public boolean isReleveActif() {
        return releveActif;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
