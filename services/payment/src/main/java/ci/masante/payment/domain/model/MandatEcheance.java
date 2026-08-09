package ci.masante.payment.domain.model;

import ci.masante.payment.domain.mandat.StatutEcheance;
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
import java.time.LocalDate;
import java.util.UUID;

/**
 * Une échéance planifiée d'un mandat (CDC_06 §5.4). {@code UNIQUE(mandat_id, numero_sequence)} = garde-fou
 * ANTI DOUBLE-PRÉLÈVEMENT (deux exécutions concurrentes de la même échéance ne peuvent créer qu'une ligne).
 * Le débit MIT réussi renseigne {@code paiementId} + {@code carteTransactionId} (traçabilité, réconciliation).
 */
@Entity
@Table(name = "mandat_echeances")
public class MandatEcheance {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "mandat_id", nullable = false, updatable = false)
    private UUID mandatId;

    @Column(name = "numero_sequence", nullable = false, updatable = false)
    private int numeroSequence;

    @Column(name = "date_prevue", nullable = false)
    private LocalDate datePrevue;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private StatutEcheance statut;

    @Column(name = "preavis_le")
    private Instant preavisLe;

    @Column(name = "execute_le")
    private Instant executeLe;

    @Column(name = "paiement_id")
    private UUID paiementId;

    @Column(name = "carte_transaction_id")
    private UUID carteTransactionId;

    @Column(name = "code_refus")
    private String codeRefus;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @UpdateTimestamp
    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    protected MandatEcheance() {
    }

    public MandatEcheance(UUID mandatId, int numeroSequence, LocalDate datePrevue, long montant, String devise) {
        this.mandatId = mandatId;
        this.numeroSequence = numeroSequence;
        this.datePrevue = datePrevue;
        this.montant = montant;
        this.devise = devise;
        this.statut = StatutEcheance.PLANIFIEE;
    }

    public void marquerPreavis(Instant quand) {
        this.statut = StatutEcheance.PREAVIS;
        this.preavisLe = quand;
    }

    public void marquerExecutee(UUID paiementId, UUID carteTransactionId, Instant quand) {
        this.statut = StatutEcheance.EXECUTEE;
        this.paiementId = paiementId;
        this.carteTransactionId = carteTransactionId;
        this.executeLe = quand;
    }

    public void marquerEchouee(UUID paiementId, UUID carteTransactionId, String codeRefus, Instant quand) {
        this.statut = StatutEcheance.ECHOUEE;
        this.paiementId = paiementId;
        this.carteTransactionId = carteTransactionId;
        this.codeRefus = codeRefus;
        this.executeLe = quand;
    }

    public void marquerSautee() {
        this.statut = StatutEcheance.SAUTEE;
    }

    public boolean estAExecuter() {
        return statut == StatutEcheance.PLANIFIEE || statut == StatutEcheance.PREAVIS;
    }

    public UUID getId() {
        return id;
    }

    public UUID getMandatId() {
        return mandatId;
    }

    public int getNumeroSequence() {
        return numeroSequence;
    }

    public LocalDate getDatePrevue() {
        return datePrevue;
    }

    public long getMontant() {
        return montant;
    }

    public String getDevise() {
        return devise;
    }

    public StatutEcheance getStatut() {
        return statut;
    }

    public Instant getPreavisLe() {
        return preavisLe;
    }

    public Instant getExecuteLe() {
        return executeLe;
    }

    public UUID getPaiementId() {
        return paiementId;
    }

    public UUID getCarteTransactionId() {
        return carteTransactionId;
    }

    public String getCodeRefus() {
        return codeRefus;
    }
}
