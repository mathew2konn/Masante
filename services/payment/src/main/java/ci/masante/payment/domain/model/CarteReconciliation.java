package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.annotations.UuidGenerator;
import org.hibernate.type.SqlTypes;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Rapport de réconciliation quotidienne carte ↔ PSP pour une journée et un PSP (CDC_06 §6.3, ADR-015).
 * VRAIE confrontation à 2 sources INDÉPENDANTES : le registre local ({@code carte_transactions}) et la
 * vérité de la passerelle ({@code recupererStatut}) — à la différence de l'auditeur d'intégrité INTERNE
 * (P5.3b-4) qui ne disposait que d'une source. Ici la source PSP est SIMULÉE (adaptateur déterministe).
 *
 * <p>Les écarts sont SIGNALÉS ({@code ecarts} JSONB), jamais corrigés automatiquement. Idempotent par
 * {@code UNIQUE(date_rapport, psp)} : réexécuter une journée recalcule le même rapport.</p>
 */
@Entity
@Table(name = "carte_reconciliations")
public class CarteReconciliation {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "date_rapport", nullable = false, updatable = false)
    private LocalDate dateRapport;

    @Column(name = "psp", nullable = false, updatable = false)
    private String psp;

    @Column(name = "nb_transactions_psp", nullable = false)
    private int nbTransactionsPsp;

    @Column(name = "nb_transactions_locales", nullable = false)
    private int nbTransactionsLocales;

    @Column(name = "montant_psp", nullable = false)
    private long montantPsp;

    @Column(name = "montant_local", nullable = false)
    private long montantLocal;

    @Column(name = "nb_ecarts", nullable = false)
    private int nbEcarts;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "ecarts", nullable = false)
    private String ecarts;

    @CreationTimestamp
    @Column(name = "genere_le", nullable = false)
    private Instant genereLe;

    protected CarteReconciliation() {
    }

    public CarteReconciliation(LocalDate dateRapport, String psp) {
        this.dateRapport = dateRapport;
        this.psp = psp;
        this.ecarts = "[]";
    }

    /** Renseigne (ou recalcule, ré-exécution idempotente) les compteurs et le détail des écarts. */
    public void renseigner(int nbTransactionsPsp, int nbTransactionsLocales, long montantPsp,
                           long montantLocal, int nbEcarts, String ecartsJson) {
        this.nbTransactionsPsp = nbTransactionsPsp;
        this.nbTransactionsLocales = nbTransactionsLocales;
        this.montantPsp = montantPsp;
        this.montantLocal = montantLocal;
        this.nbEcarts = nbEcarts;
        this.ecarts = ecartsJson;
    }

    public UUID getId() {
        return id;
    }

    public LocalDate getDateRapport() {
        return dateRapport;
    }

    public String getPsp() {
        return psp;
    }

    public int getNbTransactionsPsp() {
        return nbTransactionsPsp;
    }

    public int getNbTransactionsLocales() {
        return nbTransactionsLocales;
    }

    public long getMontantPsp() {
        return montantPsp;
    }

    public long getMontantLocal() {
        return montantLocal;
    }

    public int getNbEcarts() {
        return nbEcarts;
    }

    public String getEcarts() {
        return ecarts;
    }

    public Instant getGenereLe() {
        return genereLe;
    }
}
