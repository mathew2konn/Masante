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
 * Relevé de reversement (CDC_06 §11). Montants (XOF entiers) calculés par {@code ReglesReversement} —
 * jamais recalculés au front. Immuable hors transitions de statut : seuls {@code statut} et les champs
 * de workflow ont des mutateurs (l'immuabilité financière est garantie par l'absence de setter + REVOKE
 * déclaratif en base, cf. V10 §7). Le report est chaîné par {@code relevePrecedentId} (ADR-016 §2).
 */
@Entity
@Table(name = "reversement_releves")
public class ReversementReleve {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "numero", nullable = false, updatable = false)
    private String numero;

    @Column(name = "etablissement_ref", nullable = false, updatable = false)
    private String etablissementRef;

    @Column(name = "exercice", nullable = false, updatable = false)
    private int exercice;

    @Column(name = "periode_debut", nullable = false, updatable = false)
    private Instant periodeDebut;

    @Column(name = "periode_fin", nullable = false, updatable = false)
    private Instant periodeFin;

    @Column(name = "cut_off_t", nullable = false, updatable = false)
    private Instant cutOffT;

    @Column(name = "tentative", nullable = false, updatable = false)
    private int tentative;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "montant_brut_du", nullable = false, updatable = false)
    private long montantBrutDu;

    @Column(name = "taux_commission_bps", nullable = false, updatable = false)
    private int tauxCommissionBps;

    @Column(name = "commission_config_id", nullable = false, updatable = false)
    private UUID commissionConfigId;

    @Column(name = "montant_commission", nullable = false, updatable = false)
    private long montantCommission;

    @Column(name = "montant_rembourse", nullable = false, updatable = false)
    private long montantRembourse;

    @Column(name = "report_anterieur", nullable = false, updatable = false)
    private long reportAnterieur;

    @Column(name = "montant_net_a_reverser", nullable = false, updatable = false)
    private long montantNetAReverser;

    @Column(name = "solde_reporte", nullable = false, updatable = false)
    private long soldeReporte;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private ReversementStatut statut;

    @Column(name = "releve_precedent_id", updatable = false)
    private UUID relevePrecedentId;

    @Column(name = "hash_integrite", nullable = false, updatable = false)
    private String hashIntegrite;

    // Anti-substitution de destination : empreinte de la destination active AU CALCUL (terme gauche du
    // contrôle ; figée à la création) vs figée à l'approbation.
    @Column(name = "destination_empreinte_calcul", updatable = false)
    private String destinationEmpreinteCalcul;

    @Column(name = "destination_id")
    private UUID destinationId;

    @Column(name = "destination_empreinte")
    private String destinationEmpreinte;

    @Column(name = "destination_figee_a")
    private Instant destinationFigeeA;

    @Column(name = "calcule_par", nullable = false, updatable = false)
    private String calculePar;

    @CreationTimestamp
    @Column(name = "calcule_a", nullable = false, updatable = false)
    private Instant calculeA;

    @Column(name = "approuve_par")
    private String approuvePar;

    @Column(name = "approuve_a")
    private Instant approuveA;

    @Column(name = "annule_par")
    private String annulePar;

    @Column(name = "annule_a")
    private Instant annuleA;

    @Column(name = "motif_annulation")
    private String motifAnnulation;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @UpdateTimestamp
    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    protected ReversementReleve() {
    }

    public ReversementReleve(String numero, String etablissementRef, int exercice, Instant periodeDebut,
                             Instant periodeFin, Instant cutOffT, int tentative, String devise,
                             long montantBrutDu, int tauxCommissionBps, UUID commissionConfigId,
                             long montantCommission, long montantRembourse, long reportAnterieur,
                             long montantNetAReverser, long soldeReporte, UUID relevePrecedentId,
                             String hashIntegrite, String calculePar) {
        this.numero = numero;
        this.etablissementRef = etablissementRef;
        this.exercice = exercice;
        this.periodeDebut = periodeDebut;
        this.periodeFin = periodeFin;
        this.cutOffT = cutOffT;
        this.tentative = tentative;
        this.devise = devise;
        this.montantBrutDu = montantBrutDu;
        this.tauxCommissionBps = tauxCommissionBps;
        this.commissionConfigId = commissionConfigId;
        this.montantCommission = montantCommission;
        this.montantRembourse = montantRembourse;
        this.reportAnterieur = reportAnterieur;
        this.montantNetAReverser = montantNetAReverser;
        this.soldeReporte = soldeReporte;
        this.relevePrecedentId = relevePrecedentId;
        this.hashIntegrite = hashIntegrite;
        this.calculePar = calculePar;
        this.statut = ReversementStatut.CALCULE;
    }

    /** Empreinte de la destination active au calcul (posée avant le premier persist). */
    public void poserEmpreinteCalcul(String empreinteCalcul) {
        this.destinationEmpreinteCalcul = empreinteCalcul;
    }

    /** Approbation (CALCULE → APPROUVE) : fige la destination (quatre-yeux vérifié en amont). */
    public void approuver(String approuvePar, Instant approuveA, UUID destinationId,
                          String destinationEmpreinte, Instant destinationFigeeA) {
        this.statut = ReversementStatut.APPROUVE;
        this.approuvePar = approuvePar;
        this.approuveA = approuveA;
        this.destinationId = destinationId;
        this.destinationEmpreinte = destinationEmpreinte;
        this.destinationFigeeA = destinationFigeeA;
    }

    /** Rejet par l'approbateur (CALCULE → REJETE). Réutilise les colonnes d'annulation ; statut distinct. */
    public void rejeter(String rejetePar, Instant rejeteA, String motif) {
        this.statut = ReversementStatut.REJETE;
        this.annulePar = rejetePar;
        this.annuleA = rejeteA;
        this.motifAnnulation = motif;
    }

    /** Engage le versement (APPROUVE ou ECHOUE → EN_COURS). Garde d'état vérifiée au service. */
    public void demarrerVersement() {
        this.statut = ReversementStatut.EN_COURS;
    }

    /** Versement confirmé par la passerelle (EN_COURS → EXECUTE). Terminal. */
    public void marquerVerse() {
        this.statut = ReversementStatut.EXECUTE;
    }

    /** Versement refusé par la passerelle (EN_COURS → ECHOUE). Rejouable ; rien n'est parti. */
    public void marquerVersementEchoue() {
        this.statut = ReversementStatut.ECHOUE;
    }

    /** Annulation (depuis CALCULE, APPROUVE ou ECHOUE tant que rien n'est exécuté). */
    public void annuler(String annulePar, Instant annuleA, String motif) {
        this.statut = ReversementStatut.ANNULE;
        this.annulePar = annulePar;
        this.annuleA = annuleA;
        this.motifAnnulation = motif;
    }

    public UUID getId() {
        return id;
    }

    public String getNumero() {
        return numero;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public int getExercice() {
        return exercice;
    }

    public Instant getPeriodeDebut() {
        return periodeDebut;
    }

    public Instant getPeriodeFin() {
        return periodeFin;
    }

    public Instant getCutOffT() {
        return cutOffT;
    }

    public int getTentative() {
        return tentative;
    }

    public String getDevise() {
        return devise;
    }

    public long getMontantBrutDu() {
        return montantBrutDu;
    }

    public int getTauxCommissionBps() {
        return tauxCommissionBps;
    }

    public UUID getCommissionConfigId() {
        return commissionConfigId;
    }

    public long getMontantCommission() {
        return montantCommission;
    }

    public long getMontantRembourse() {
        return montantRembourse;
    }

    public long getReportAnterieur() {
        return reportAnterieur;
    }

    public long getMontantNetAReverser() {
        return montantNetAReverser;
    }

    public long getSoldeReporte() {
        return soldeReporte;
    }

    public ReversementStatut getStatut() {
        return statut;
    }

    public UUID getRelevePrecedentId() {
        return relevePrecedentId;
    }

    public String getHashIntegrite() {
        return hashIntegrite;
    }

    public String getDestinationEmpreinteCalcul() {
        return destinationEmpreinteCalcul;
    }

    public UUID getDestinationId() {
        return destinationId;
    }

    public String getDestinationEmpreinte() {
        return destinationEmpreinte;
    }

    public Instant getDestinationFigeeA() {
        return destinationFigeeA;
    }

    public String getCalculePar() {
        return calculePar;
    }

    public Instant getCalculeA() {
        return calculeA;
    }

    public String getApprouvePar() {
        return approuvePar;
    }

    public Instant getApprouveA() {
        return approuveA;
    }

    public String getAnnulePar() {
        return annulePar;
    }

    public Instant getAnnuleA() {
        return annuleA;
    }

    public String getMotifAnnulation() {
        return motifAnnulation;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }

    public Instant getUpdatedAt() {
        return updatedAt;
    }

    public long getVersion() {
        return version;
    }
}
