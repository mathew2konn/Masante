package ci.masante.payment.domain.model;

import ci.masante.payment.domain.integrity.ControleStatut;
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
import java.time.LocalDate;
import java.util.UUID;

/**
 * Rapport de rapprochement à DEUX sources « factures ↔ reversements » pour une journée (P5.5c,
 * CDC_06 §11, ADR-016 §7). VRAIE confrontation entre la facturation (source A) et les reversements
 * (source B) — à la différence de l'auditeur d'intégrité INTERNE (P5.3b-4) qui ne disposait que d'une
 * source. Le bras « opérateurs ↔ MASANTÉ » reste différé (FT5).
 *
 * <p>Les écarts sont SIGNALÉS ({@code ecarts} JSONB), jamais corrigés automatiquement (CDC_06 §11).
 * Idempotent par {@code UNIQUE(date_rapport)} : réexécuter une journée recalcule le même rapport.
 * Réutilise {@link ControleStatut} (OK|ECARTS) — vocabulaire commun au domaine contrôle.</p>
 */
@Entity
@Table(name = "reversement_reconciliations")
public class ReversementReconciliation {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "date_rapport", nullable = false, updatable = false)
    private LocalDate dateRapport;

    @Column(name = "cut_off_t", nullable = false)
    private Instant cutOffT;

    @Column(name = "grace_jours", nullable = false)
    private int graceJours;

    @Column(name = "grace_cut_off", nullable = false)
    private Instant graceCutOff;

    @Column(name = "nb_pieces_examinees", nullable = false)
    private int nbPiecesExaminees;

    @Column(name = "nb_lignes_examinees", nullable = false)
    private int nbLignesExaminees;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private ControleStatut statut;

    @Column(name = "nb_ecarts", nullable = false)
    private int nbEcarts;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "ecarts", nullable = false)
    private String ecarts;

    @CreationTimestamp
    @Column(name = "genere_le", nullable = false)
    private Instant genereLe;

    protected ReversementReconciliation() {
    }

    public ReversementReconciliation(LocalDate dateRapport) {
        this.dateRapport = dateRapport;
        this.ecarts = "[]";
        this.statut = ControleStatut.OK;
    }

    /** Renseigne (ou recalcule, ré-exécution idempotente) les compteurs et le détail des écarts. */
    public void renseigner(Instant cutOffT, int graceJours, Instant graceCutOff, int nbPiecesExaminees,
                           int nbLignesExaminees, int nbEcarts, String ecartsJson) {
        this.cutOffT = cutOffT;
        this.graceJours = graceJours;
        this.graceCutOff = graceCutOff;
        this.nbPiecesExaminees = nbPiecesExaminees;
        this.nbLignesExaminees = nbLignesExaminees;
        this.nbEcarts = nbEcarts;
        this.ecarts = ecartsJson;
        this.statut = nbEcarts == 0 ? ControleStatut.OK : ControleStatut.ECARTS;
    }

    public UUID getId() {
        return id;
    }

    public LocalDate getDateRapport() {
        return dateRapport;
    }

    public Instant getCutOffT() {
        return cutOffT;
    }

    public int getGraceJours() {
        return graceJours;
    }

    public Instant getGraceCutOff() {
        return graceCutOff;
    }

    public int getNbPiecesExaminees() {
        return nbPiecesExaminees;
    }

    public int getNbLignesExaminees() {
        return nbLignesExaminees;
    }

    public ControleStatut getStatut() {
        return statut;
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
