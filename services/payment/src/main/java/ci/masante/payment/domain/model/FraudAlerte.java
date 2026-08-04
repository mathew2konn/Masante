package ci.masante.payment.domain.model;

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
import java.util.UUID;

/**
 * Alerte de suspicion de fraude (CDC_06 §6.4, §9.10). Comme l'opération est BLOQUÉE (aucune écriture
 * créée), l'alerte référence le <b>wallet</b> et le <b>montant tenté</b>. {@code motifs} et
 * {@code parametres} sont des snapshots <b>JSONB</b> : rejouabilité du score même si les seuils changent.
 */
@Entity
@Table(name = "fraud_alertes")
public class FraudAlerte {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "wallet_id", nullable = false, updatable = false)
    private UUID walletId;

    @Column(name = "score", nullable = false, updatable = false)
    private int score;

    @Column(name = "palier", nullable = false, updatable = false)
    private String palier;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "motifs", nullable = false, updatable = false)
    private String motifs;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "parametres", nullable = false, updatable = false)
    private String parametres;

    @Column(name = "montant_tente", nullable = false, updatable = false)
    private long montantTente;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private StatutAlerteFraude statut;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @Column(name = "revue_at")
    private Instant revueAt;

    @Column(name = "revue_par")
    private String revuePar;

    protected FraudAlerte() {
    }

    public FraudAlerte(UUID walletId, int score, String palier, String motifs, String parametres,
                       long montantTente) {
        this.walletId = walletId;
        this.score = score;
        this.palier = palier;
        this.motifs = motifs;
        this.parametres = parametres;
        this.montantTente = montantTente;
        this.statut = StatutAlerteFraude.OUVERTE;
    }

    /** Marque l'alerte revue (ne dégèle rien : le dégel reste une action distincte). */
    public void marquerRevue(String par, Instant quand) {
        this.statut = StatutAlerteFraude.REVUE;
        this.revuePar = par;
        this.revueAt = quand;
    }

    public UUID getId() {
        return id;
    }

    public UUID getWalletId() {
        return walletId;
    }

    public int getScore() {
        return score;
    }

    public String getPalier() {
        return palier;
    }

    public String getMotifs() {
        return motifs;
    }

    public String getParametres() {
        return parametres;
    }

    public long getMontantTente() {
        return montantTente;
    }

    public StatutAlerteFraude getStatut() {
        return statut;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }

    public Instant getRevueAt() {
        return revueAt;
    }

    public String getRevuePar() {
        return revuePar;
    }
}
