package ci.masante.payment.domain.model;

import ci.masante.payment.domain.mandat.MandatStatut;
import ci.masante.payment.domain.mandat.Periodicite;
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
import java.time.LocalDate;
import java.util.UUID;

/**
 * Mandat de paiement récurrent (CDC_06 §5.4). S'appuie sur une carte tokenisée du vault ({@code carteId}) ;
 * l'exécution est un débit MIT (token + NTID). Montant, périodicité, prochaine échéance, transitions =
 * BACKEND (frontière §0.1). {@code statut} est FOURNI par le backend, jamais déduit par le front.
 */
@Entity
@Table(name = "mandats")
public class Mandat {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "idempotency_key", nullable = false, updatable = false)
    private String idempotencyKey;

    @Column(name = "utilisateur_ref", nullable = false, updatable = false)
    private String utilisateurRef;

    @Column(name = "carte_id", nullable = false, updatable = false)
    private UUID carteId;

    @Column(name = "psp", nullable = false, updatable = false)
    private String psp;

    @Enumerated(EnumType.STRING)
    @Column(name = "objet", nullable = false, updatable = false)
    private ObjetPaiement objet;

    @Column(name = "libelle")
    private String libelle;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Enumerated(EnumType.STRING)
    @Column(name = "periodicite", nullable = false, updatable = false)
    private Periodicite periodicite;

    @Column(name = "date_debut", nullable = false, updatable = false)
    private LocalDate dateDebut;

    @Column(name = "date_fin")
    private LocalDate dateFin;

    @Column(name = "prochaine_echeance")
    private LocalDate prochaineEcheance;

    @Column(name = "preavis_jours", nullable = false)
    private int preavisJours;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private MandatStatut statut;

    @Column(name = "sequence_courante", nullable = false)
    private int sequenceCourante;

    @Column(name = "etablissement_ref")
    private String etablissementRef;

    @Column(name = "patient_ref")
    private String patientRef;

    @Column(name = "acteur")
    private String acteur;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @UpdateTimestamp
    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    @Column(name = "cloture_le")
    private Instant clotureLe;

    protected Mandat() {
    }

    public Mandat(String idempotencyKey, String utilisateurRef, UUID carteId, String psp, ObjetPaiement objet,
                  String libelle, long montant, String devise, Periodicite periodicite, LocalDate dateDebut,
                  LocalDate dateFin, int preavisJours, String etablissementRef, String patientRef, String acteur) {
        this.idempotencyKey = idempotencyKey;
        this.utilisateurRef = utilisateurRef;
        this.carteId = carteId;
        this.psp = psp;
        this.objet = objet;
        this.libelle = libelle;
        this.montant = montant;
        this.devise = devise;
        this.periodicite = periodicite;
        this.dateDebut = dateDebut;
        this.dateFin = dateFin;
        this.prochaineEcheance = dateDebut;
        this.preavisJours = preavisJours;
        this.etablissementRef = etablissementRef;
        this.patientRef = patientRef;
        this.acteur = acteur;
        this.statut = MandatStatut.ACTIF;
        this.sequenceCourante = 0;
    }

    public void changerStatut(MandatStatut nouveau) {
        this.statut = nouveau;
        if (nouveau == MandatStatut.ANNULE || nouveau == MandatStatut.EXPIRE) {
            this.clotureLe = Instant.now();
            this.prochaineEcheance = null;
        }
    }

    public void avancer(int sequence, LocalDate prochaine) {
        this.sequenceCourante = sequence;
        this.prochaineEcheance = prochaine;
    }

    public boolean finAtteinte(LocalDate date) {
        return dateFin != null && date.isAfter(dateFin);
    }

    public UUID getId() {
        return id;
    }

    public String getIdempotencyKey() {
        return idempotencyKey;
    }

    public String getUtilisateurRef() {
        return utilisateurRef;
    }

    public UUID getCarteId() {
        return carteId;
    }

    public String getPsp() {
        return psp;
    }

    public ObjetPaiement getObjet() {
        return objet;
    }

    public String getLibelle() {
        return libelle;
    }

    public long getMontant() {
        return montant;
    }

    public String getDevise() {
        return devise;
    }

    public Periodicite getPeriodicite() {
        return periodicite;
    }

    public LocalDate getDateDebut() {
        return dateDebut;
    }

    public LocalDate getDateFin() {
        return dateFin;
    }

    public LocalDate getProchaineEcheance() {
        return prochaineEcheance;
    }

    public int getPreavisJours() {
        return preavisJours;
    }

    public MandatStatut getStatut() {
        return statut;
    }

    public int getSequenceCourante() {
        return sequenceCourante;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public String getPatientRef() {
        return patientRef;
    }

    public String getActeur() {
        return acteur;
    }

    public Instant getCreeLe() {
        return creeLe;
    }

    public Instant getClotureLe() {
        return clotureLe;
    }
}
