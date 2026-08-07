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
 * Carte tokenisée (vault, CDC_06 §5.2, prêt pour §5.4). FRONTIÈRE PCI : ne contient JAMAIS de PAN/CVV —
 * seulement un {@code token} et des métadonnées non sensibles fournies par la passerelle. Le
 * {@code networkTransactionId} (NTID de la 1re transaction) est conservé pour les paiements récurrents MIT.
 */
@Entity
@Table(name = "cartes")
public class Carte {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "utilisateur_ref", nullable = false, updatable = false)
    private String utilisateurRef;

    @Column(name = "psp", nullable = false, updatable = false)
    private String psp;

    @Column(name = "psp_customer_id", updatable = false)
    private String pspCustomerId;

    @Column(name = "token", nullable = false, updatable = false)
    private String token;

    @Column(name = "empreinte", updatable = false)
    private String empreinte;

    @Column(name = "marque", nullable = false, updatable = false)
    private String marque;

    @Column(name = "last4", nullable = false, updatable = false)
    private String last4;

    @Column(name = "exp_mois", nullable = false, updatable = false)
    private int expMois;

    @Column(name = "exp_annee", nullable = false, updatable = false)
    private int expAnnee;

    @Column(name = "network_transaction_id")
    private String networkTransactionId;

    @Column(name = "par_defaut", nullable = false)
    private boolean parDefaut;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @UpdateTimestamp
    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    @Column(name = "supprime_le")
    private Instant supprimeLe;

    protected Carte() {
    }

    public Carte(String utilisateurRef, String psp, String pspCustomerId, String token, String empreinte,
                 String marque, String last4, int expMois, int expAnnee, String networkTransactionId) {
        this.utilisateurRef = utilisateurRef;
        this.psp = psp;
        this.pspCustomerId = pspCustomerId;
        this.token = token;
        this.empreinte = empreinte;
        this.marque = marque;
        this.last4 = last4;
        this.expMois = expMois;
        this.expAnnee = expAnnee;
        this.networkTransactionId = networkTransactionId;
    }

    public void marquerSupprimee(Instant quand) {
        this.supprimeLe = quand;
    }

    public void definirParDefaut(boolean parDefaut) {
        this.parDefaut = parDefaut;
    }

    public UUID getId() {
        return id;
    }

    public String getUtilisateurRef() {
        return utilisateurRef;
    }

    public String getPsp() {
        return psp;
    }

    public String getPspCustomerId() {
        return pspCustomerId;
    }

    public String getToken() {
        return token;
    }

    public String getEmpreinte() {
        return empreinte;
    }

    public String getMarque() {
        return marque;
    }

    public String getLast4() {
        return last4;
    }

    public int getExpMois() {
        return expMois;
    }

    public int getExpAnnee() {
        return expAnnee;
    }

    public String getNetworkTransactionId() {
        return networkTransactionId;
    }

    public boolean isParDefaut() {
        return parDefaut;
    }

    public Instant getCreeLe() {
        return creeLe;
    }

    public Instant getSupprimeLe() {
        return supprimeLe;
    }
}
