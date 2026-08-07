package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UpdateTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.util.UUID;

/**
 * Remboursement (total ou partiel) d'une transaction carte (CDC_06 §5). {@code UNIQUE(psp,
 * ref_passerelle_remboursement)} = idempotence côté passerelle. Le remboursement va TOUJOURS vers la carte
 * d'origine (interdit #10).
 */
@Entity
@Table(name = "carte_remboursements")
public class CarteRemboursement {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "carte_transaction_id", nullable = false, updatable = false)
    private UUID carteTransactionId;

    @Column(name = "psp", nullable = false, updatable = false)
    private String psp;

    @Column(name = "ref_passerelle_remboursement", nullable = false, updatable = false)
    private String refPasserelleRemboursement;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "statut", nullable = false)
    private String statut;

    @Column(name = "motif", updatable = false)
    private String motif;

    // Établissement figé à la création (dénormalisé §11) : évite le rattachement à 3 sauts sur des
    // données mutables. Assiette reversement = statut REUSSI ∧ cree_le ∈ fenêtre ∧ non déjà imputé.
    @Column(name = "etablissement_ref", updatable = false)
    private String etablissementRef;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @UpdateTimestamp
    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    protected CarteRemboursement() {
    }

    public CarteRemboursement(UUID carteTransactionId, String psp, String refPasserelleRemboursement,
                              long montant, String devise, String statut, String motif,
                              String etablissementRef) {
        this.carteTransactionId = carteTransactionId;
        this.psp = psp;
        this.refPasserelleRemboursement = refPasserelleRemboursement;
        this.montant = montant;
        this.devise = devise;
        this.statut = statut;
        this.motif = motif;
        this.etablissementRef = etablissementRef;
    }

    public void setStatut(String statut) {
        this.statut = statut;
    }

    public UUID getId() {
        return id;
    }

    public UUID getCarteTransactionId() {
        return carteTransactionId;
    }

    public String getPsp() {
        return psp;
    }

    public String getRefPasserelleRemboursement() {
        return refPasserelleRemboursement;
    }

    public long getMontant() {
        return montant;
    }

    public String getDevise() {
        return devise;
    }

    public String getStatut() {
        return statut;
    }

    public String getMotif() {
        return motif;
    }

    public Instant getCreeLe() {
        return creeLe;
    }
}
