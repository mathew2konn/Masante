package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import jakarta.persistence.Version;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.annotations.UuidGenerator;
import org.hibernate.type.SqlTypes;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Alerte de fraude IA sur une FACTURE (CDC_05), routée vers le contrôleur plateforme {@code ADMIN_FINANCE}.
 * Distincte de {@link FraudAlerte} (garde temps-réel wallet, P5.3b-2). {@code regles}/{@code facteurs}/
 * {@code signaux} sont des snapshots <b>JSONB</b> (rejouabilité du verdict). Une alerte au plus par
 * {@code (facture_ref, date_rapport)} : rejouer un scan MET À JOUR la même ligne (idempotence).
 *
 * <p>DÉTECTION SEULE : cette alerte notifie un humain ; elle ne gèle/corrige rien.</p>
 */
@Entity
@Table(name = "ia_fraude_alertes")
public class AlerteFraudeIa {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "facture_ref", nullable = false, updatable = false)
    private String factureRef;

    @Column(name = "etablissement_ref", nullable = false)
    private String etablissementRef;

    @Column(name = "patient_ref")
    private String patientRef;

    @Column(name = "date_rapport", nullable = false, updatable = false)
    private LocalDate dateRapport;

    @Enumerated(EnumType.STRING)
    @Column(name = "niveau", nullable = false)
    private NiveauFraudeIa niveau;

    @Column(name = "score", nullable = false)
    private int score;

    @Column(name = "mode", nullable = false)
    private String mode;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "regles", nullable = false)
    private String regles;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "facteurs", nullable = false)
    private String facteurs;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "signaux", nullable = false)
    private String signaux;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private StatutAlerteFraudeIa statut;

    @Column(name = "notifiee", nullable = false)
    private boolean notifiee;

    @Column(name = "cut_off", nullable = false)
    private Instant cutOff;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    @Column(name = "revue_at")
    private Instant revueAt;

    @Column(name = "revue_par")
    private String revuePar;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    protected AlerteFraudeIa() {
    }

    public AlerteFraudeIa(String factureRef, String etablissementRef, String patientRef, LocalDate dateRapport,
                          NiveauFraudeIa niveau, int score, String mode, String regles, String facteurs,
                          String signaux, Instant cutOff) {
        this.factureRef = factureRef;
        this.etablissementRef = etablissementRef;
        this.patientRef = patientRef;
        this.dateRapport = dateRapport;
        this.niveau = niveau;
        this.score = score;
        this.mode = mode;
        this.regles = regles;
        this.facteurs = facteurs;
        this.signaux = signaux;
        this.cutOff = cutOff;
        this.statut = StatutAlerteFraudeIa.OUVERTE;
        this.notifiee = false;
        this.majLe = Instant.now();
    }

    /** Réévaluation (rejeu d'un scan) : rafraîchit le verdict sans dupliquer ni ré-ouvrir une alerte revue. */
    public void reevaluer(NiveauFraudeIa niveau, int score, String mode, String regles, String facteurs,
                          String signaux, Instant cutOff) {
        this.niveau = niveau;
        this.score = score;
        this.mode = mode;
        this.regles = regles;
        this.facteurs = facteurs;
        this.signaux = signaux;
        this.cutOff = cutOff;
        this.majLe = Instant.now();
    }

    public void marquerNotifiee() {
        this.notifiee = true;
        this.majLe = Instant.now();
    }

    public void marquerRevue(String par, Instant quand) {
        this.statut = StatutAlerteFraudeIa.REVUE;
        this.revuePar = par;
        this.revueAt = quand;
        this.majLe = quand;
    }

    public UUID getId() {
        return id;
    }

    public String getFactureRef() {
        return factureRef;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public String getPatientRef() {
        return patientRef;
    }

    public LocalDate getDateRapport() {
        return dateRapport;
    }

    public NiveauFraudeIa getNiveau() {
        return niveau;
    }

    public int getScore() {
        return score;
    }

    public String getMode() {
        return mode;
    }

    public String getRegles() {
        return regles;
    }

    public String getFacteurs() {
        return facteurs;
    }

    public String getSignaux() {
        return signaux;
    }

    public StatutAlerteFraudeIa getStatut() {
        return statut;
    }

    public boolean isNotifiee() {
        return notifiee;
    }

    public Instant getCutOff() {
        return cutOff;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }

    public Instant getMajLe() {
        return majLe;
    }

    public Instant getRevueAt() {
        return revueAt;
    }

    public String getRevuePar() {
        return revuePar;
    }
}
