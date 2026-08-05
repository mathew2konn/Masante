package ci.masante.payment.domain.model;

import ci.masante.payment.domain.integrity.ControleStatut;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Un run de contrôle d'intégrité (P5.3b-4) : le verdict d'une journée comptable, arrêté à un cut-off T.
 * Persisté (ce n'est pas un script jetable) et idempotent — au plus un run par journée.
 */
@Entity
@Table(name = "controle_runs")
public class ControleRun {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "journee", nullable = false, updatable = false)
    private LocalDate journee;

    @Column(name = "arrete_a", nullable = false, updatable = false)
    private Instant arreteA;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private ControleStatut statut;

    @Column(name = "nb_controles", nullable = false)
    private int nbControles;

    @Column(name = "nb_ecarts", nullable = false)
    private int nbEcarts;

    @Column(name = "duree_ms", nullable = false)
    private long dureeMs;

    @CreationTimestamp
    @Column(name = "execute_a", nullable = false, updatable = false)
    private Instant executeA;

    protected ControleRun() {
    }

    public ControleRun(LocalDate journee, Instant arreteA, ControleStatut statut,
                       int nbControles, int nbEcarts, long dureeMs) {
        this.journee = journee;
        this.arreteA = arreteA;
        this.statut = statut;
        this.nbControles = nbControles;
        this.nbEcarts = nbEcarts;
        this.dureeMs = dureeMs;
    }

    public UUID getId() {
        return id;
    }

    public LocalDate getJournee() {
        return journee;
    }

    public Instant getArreteA() {
        return arreteA;
    }

    public ControleStatut getStatut() {
        return statut;
    }

    public int getNbControles() {
        return nbControles;
    }

    public int getNbEcarts() {
        return nbEcarts;
    }

    public long getDureeMs() {
        return dureeMs;
    }

    public Instant getExecuteA() {
        return executeA;
    }
}
