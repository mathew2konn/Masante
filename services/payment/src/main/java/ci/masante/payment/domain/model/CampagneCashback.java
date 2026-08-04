package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.util.UUID;

/**
 * Campagne de cashback (CDC_06 §6.1/§6.2). Règles = <b>données</b> : taux (bps), plafonds et budget
 * sont portés ici, jamais codés. Le calcul reste backend seul ({@code ReglesCashback}).
 */
@Entity
@Table(name = "cashback_campagnes")
public class CampagneCashback {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "code", nullable = false, updatable = false, unique = true)
    private String code;

    @Column(name = "libelle", nullable = false)
    private String libelle;

    @Column(name = "type_operation_source", nullable = false, updatable = false)
    private String typeOperationSource;

    @Column(name = "taux_bps", nullable = false)
    private int tauxBps;

    @Column(name = "plafond_par_operation", nullable = false)
    private long plafondParOperation;

    @Column(name = "plafond_par_wallet", nullable = false)
    private long plafondParWallet;

    @Column(name = "plafond_par_wallet_par_jour", nullable = false)
    private long plafondParWalletParJour;

    @Column(name = "budget_total")
    private Long budgetTotal;

    @Column(name = "date_debut", nullable = false)
    private Instant dateDebut;

    @Column(name = "date_fin", nullable = false)
    private Instant dateFin;

    @Column(name = "actif", nullable = false)
    private boolean actif;

    @Column(name = "cree_par", nullable = false, updatable = false)
    private String creePar;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected CampagneCashback() {
    }

    public CampagneCashback(String code, String libelle, String typeOperationSource, int tauxBps,
                            long plafondParOperation, long plafondParWallet, long plafondParWalletParJour,
                            Long budgetTotal, Instant dateDebut, Instant dateFin, String creePar) {
        this.code = code;
        this.libelle = libelle;
        this.typeOperationSource = typeOperationSource;
        this.tauxBps = tauxBps;
        this.plafondParOperation = plafondParOperation;
        this.plafondParWallet = plafondParWallet;
        this.plafondParWalletParJour = plafondParWalletParJour;
        this.budgetTotal = budgetTotal;
        this.dateDebut = dateDebut;
        this.dateFin = dateFin;
        this.creePar = creePar;
        this.actif = true;
    }

    /** Active ET dans sa période [debut, fin]. */
    public boolean estValide(Instant maintenant) {
        return actif && !maintenant.isBefore(dateDebut) && !maintenant.isAfter(dateFin);
    }

    public void desactiver() {
        this.actif = false;
    }

    /** true si un budget/plafond est posé → l'octroi doit se sérialiser (verrou). Sinon inutile. */
    public boolean exigeSerialisation() {
        return budgetTotal != null || plafondParOperation > 0
                || plafondParWallet > 0 || plafondParWalletParJour > 0;
    }

    public UUID getId() {
        return id;
    }

    public String getCode() {
        return code;
    }

    public String getLibelle() {
        return libelle;
    }

    public String getTypeOperationSource() {
        return typeOperationSource;
    }

    public int getTauxBps() {
        return tauxBps;
    }

    public long getPlafondParOperation() {
        return plafondParOperation;
    }

    public long getPlafondParWallet() {
        return plafondParWallet;
    }

    public long getPlafondParWalletParJour() {
        return plafondParWalletParJour;
    }

    public Long getBudgetTotal() {
        return budgetTotal;
    }

    public Instant getDateDebut() {
        return dateDebut;
    }

    public Instant getDateFin() {
        return dateFin;
    }

    public boolean isActif() {
        return actif;
    }

    public String getCreePar() {
        return creePar;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
