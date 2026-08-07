package ci.masante.payment.domain.model;

import ci.masante.payment.domain.carte.ModaliteCarte;
import ci.masante.payment.domain.carte.StatutCarte;
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
 * Cycle carte (3DS2 / autorisation / capture / remboursement) d'UN paiement (relation 1:1 garantie par
 * {@code UNIQUE(paiement_id)}). {@code statutCarte} = sous-état backend-only ({@link StatutCarte}), projeté
 * vers {@code PaiementStatut} au niveau service. {@code version} = verrou OPTIMISTE ; un verrou PESSIMISTE
 * ({@code FOR UPDATE}) est en outre pris à la finalisation concurrente. {@code challengeRefHash} = SHA-256
 * (jamais la valeur en clair).
 */
@Entity
@Table(name = "carte_transactions")
public class CarteTransaction {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "paiement_id", nullable = false, updatable = false)
    private UUID paiementId;

    @Column(name = "carte_id")
    private UUID carteId;

    @Column(name = "psp", nullable = false, updatable = false)
    private String psp;

    @Enumerated(EnumType.STRING)
    @Column(name = "modalite", nullable = false, updatable = false)
    private ModaliteCarte modalite;

    @Column(name = "ref_passerelle", nullable = false, updatable = false)
    private String refPasserelle;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut_carte", nullable = false)
    private StatutCarte statutCarte;

    @Column(name = "challenge_ref_hash")
    private String challengeRefHash;

    @Column(name = "challenge_expire_le")
    private Instant challengeExpireLe;

    @Column(name = "autorisation_expire_le")
    private Instant autorisationExpireLe;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "montant_capture", nullable = false)
    private long montantCapture;

    @Column(name = "montant_rembourse", nullable = false)
    private long montantRembourse;

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

    protected CarteTransaction() {
    }

    public CarteTransaction(UUID paiementId, String psp, ModaliteCarte modalite, String refPasserelle,
                            StatutCarte statutInitial, long montant, String devise) {
        this.paiementId = paiementId;
        this.psp = psp;
        this.modalite = modalite;
        this.refPasserelle = refPasserelle;
        this.statutCarte = statutInitial;
        this.montant = montant;
        this.devise = devise;
    }

    public UUID getId() {
        return id;
    }

    public UUID getPaiementId() {
        return paiementId;
    }

    public UUID getCarteId() {
        return carteId;
    }

    public void setCarteId(UUID carteId) {
        this.carteId = carteId;
    }

    public String getPsp() {
        return psp;
    }

    public ModaliteCarte getModalite() {
        return modalite;
    }

    public String getRefPasserelle() {
        return refPasserelle;
    }

    public StatutCarte getStatutCarte() {
        return statutCarte;
    }

    public void setStatutCarte(StatutCarte statutCarte) {
        this.statutCarte = statutCarte;
    }

    public String getChallengeRefHash() {
        return challengeRefHash;
    }

    public void setChallengeRefHash(String challengeRefHash) {
        this.challengeRefHash = challengeRefHash;
    }

    public Instant getChallengeExpireLe() {
        return challengeExpireLe;
    }

    public void setChallengeExpireLe(Instant challengeExpireLe) {
        this.challengeExpireLe = challengeExpireLe;
    }

    public Instant getAutorisationExpireLe() {
        return autorisationExpireLe;
    }

    public void setAutorisationExpireLe(Instant autorisationExpireLe) {
        this.autorisationExpireLe = autorisationExpireLe;
    }

    public long getMontant() {
        return montant;
    }

    public String getDevise() {
        return devise;
    }

    public long getMontantCapture() {
        return montantCapture;
    }

    public void setMontantCapture(long montantCapture) {
        this.montantCapture = montantCapture;
    }

    public long getMontantRembourse() {
        return montantRembourse;
    }

    public void setMontantRembourse(long montantRembourse) {
        this.montantRembourse = montantRembourse;
    }

    public String getCodeRefus() {
        return codeRefus;
    }

    public void setCodeRefus(String codeRefus) {
        this.codeRefus = codeRefus;
    }

    public long getVersion() {
        return version;
    }

    public Instant getCreeLe() {
        return creeLe;
    }
}
