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
 * Détail GeniusPay d'un paiement — table <b>satellite</b> de {@code payments}, sur le modèle de
 * {@code carte_transactions} (P5.4a).
 *
 * <p>Elle ne porte QUE ce que le paiement partagé ne sait pas dire : référence interne, référence
 * passerelle, lien de checkout, échéance, frais réels, sous-état. Montant, devise, établissement et
 * téléphone masqué vivent sur {@code Paiement} et n'y sont pas recopiés — deux copies d'un même fait
 * finissent toujours par diverger, et ici l'écart porterait sur un montant.</p>
 */
@Entity
@Table(name = "geniuspay_transactions")
public class GeniusPayTransaction {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "paiement_id", nullable = false, updatable = false)
    private UUID paiementId;

    @Column(name = "reference_interne", nullable = false, updatable = false)
    private String referenceInterne;

    @Column(name = "reference_passerelle")
    private String referencePasserelle;

    @Column(name = "facture_id", updatable = false)
    private UUID factureId;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut_geniuspay", nullable = false)
    private StatutGeniusPay statutGeniusPay;

    @Column(name = "canal")
    private String canal;

    @Column(name = "frais_passerelle")
    private Long fraisPasserelle;

    @Column(name = "montant_net")
    private Long montantNet;

    @Column(name = "checkout_url")
    private String checkoutUrl;

    @Column(name = "expire_le")
    private Instant expireLe;

    @Column(name = "code_erreur")
    private String codeErreur;

    @Column(name = "initiee_le", nullable = false, updatable = false)
    private Instant initieeLe;

    @Column(name = "finalisee_le")
    private Instant finaliseeLe;

    @Column(name = "derniere_verification_le")
    private Instant derniereVerificationLe;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @UpdateTimestamp
    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    protected GeniusPayTransaction() {
    }

    public GeniusPayTransaction(UUID paiementId, String referenceInterne, UUID factureId) {
        this.paiementId = paiementId;
        this.referenceInterne = referenceInterne;
        this.factureId = factureId;
        this.statutGeniusPay = StatutGeniusPay.INITIEE;
        this.initieeLe = Instant.now();
    }

    /** Le lien de checkout est-il encore utilisable ? Un lien échu ne se réutilise jamais (§7.5.3). */
    public boolean checkoutUtilisable(Instant maintenant) {
        return checkoutUrl != null && expireLe != null && expireLe.isAfter(maintenant);
    }

    public UUID getId() {
        return id;
    }

    public UUID getPaiementId() {
        return paiementId;
    }

    public String getReferenceInterne() {
        return referenceInterne;
    }

    public String getReferencePasserelle() {
        return referencePasserelle;
    }

    public void setReferencePasserelle(String referencePasserelle) {
        this.referencePasserelle = referencePasserelle;
    }

    public UUID getFactureId() {
        return factureId;
    }

    public StatutGeniusPay getStatutGeniusPay() {
        return statutGeniusPay;
    }

    public void setStatutGeniusPay(StatutGeniusPay statutGeniusPay) {
        this.statutGeniusPay = statutGeniusPay;
    }

    public String getCanal() {
        return canal;
    }

    public void setCanal(String canal) {
        this.canal = canal;
    }

    public Long getFraisPasserelle() {
        return fraisPasserelle;
    }

    public void setFraisPasserelle(Long fraisPasserelle) {
        this.fraisPasserelle = fraisPasserelle;
    }

    public Long getMontantNet() {
        return montantNet;
    }

    public void setMontantNet(Long montantNet) {
        this.montantNet = montantNet;
    }

    public String getCheckoutUrl() {
        return checkoutUrl;
    }

    public void setCheckoutUrl(String checkoutUrl) {
        this.checkoutUrl = checkoutUrl;
    }

    public Instant getExpireLe() {
        return expireLe;
    }

    public void setExpireLe(Instant expireLe) {
        this.expireLe = expireLe;
    }

    public String getCodeErreur() {
        return codeErreur;
    }

    public void setCodeErreur(String codeErreur) {
        this.codeErreur = codeErreur;
    }

    public Instant getInitieeLe() {
        return initieeLe;
    }

    public Instant getFinaliseeLe() {
        return finaliseeLe;
    }

    public void setFinaliseeLe(Instant finaliseeLe) {
        this.finaliseeLe = finaliseeLe;
    }

    public Instant getDerniereVerificationLe() {
        return derniereVerificationLe;
    }

    public void setDerniereVerificationLe(Instant derniereVerificationLe) {
        this.derniereVerificationLe = derniereVerificationLe;
    }

    public long getVersion() {
        return version;
    }
}
