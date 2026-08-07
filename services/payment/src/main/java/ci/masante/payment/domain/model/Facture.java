package ci.masante.payment.domain.model;

import ci.masante.payment.domain.coverage.TypePriseEnCharge;
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
 * Facture (CDC_06 §7). Montants (FCFA) calculés par {@code MoteurFacturation} — jamais recalculés au
 * front. {@code resteAPayer} = dû patient après prise en charge ; {@code montantRegle} = cumul payé.
 */
@Entity
@Table(name = "factures")
public class Facture {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "numero", nullable = false, updatable = false)
    private String numero;

    @Column(name = "etablissement_ref", nullable = false, updatable = false)
    private String etablissementRef;

    @Column(name = "patient_ref", updatable = false)
    private String patientRef;

    @Column(name = "exercice", nullable = false, updatable = false)
    private int exercice;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "sous_total_ht", nullable = false, updatable = false)
    private long sousTotalHt;

    @Column(name = "total_remises", nullable = false, updatable = false)
    private long totalRemises;

    @Column(name = "total_tva", nullable = false, updatable = false)
    private long totalTva;

    @Column(name = "montant_ttc", nullable = false, updatable = false)
    private long montantTtc;

    @Enumerated(EnumType.STRING)
    @Column(name = "couverture_type", updatable = false)
    private TypePriseEnCharge couvertureType;

    @Column(name = "couverture_taux", updatable = false)
    private Integer couvertureTaux;

    @Column(name = "montant_couvert", nullable = false, updatable = false)
    private long montantCouvert;

    @Column(name = "reste_a_payer", nullable = false, updatable = false)
    private long resteAPayer;

    @Column(name = "montant_regle", nullable = false)
    private long montantRegle;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private FactureStatut statut;

    @Column(name = "hash_integrite", nullable = false, updatable = false)
    private String hashIntegrite;

    // Versionnage (§7.5) : la lignée partage le numéro ; une correction incrémente la version.
    @Column(name = "version_numero", nullable = false, updatable = false)
    private int versionNumero = 1;

    @Column(name = "origine_facture_id", updatable = false)
    private UUID origineFactureId;

    @Column(name = "remplacee_par_id")
    private UUID remplaceeParId;

    // Signature (§7.4) : posée à la création, avant le premier persist.
    @Column(name = "signature", updatable = false)
    private String signature;

    @Column(name = "signature_pubkey", updatable = false)
    private String signaturePubkey;

    @Column(name = "signature_algo", updatable = false)
    private String signatureAlgo;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @UpdateTimestamp
    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    // Assiette temporelle des reversements (§11) : posée UNE fois au passage à PAYEE par le trigger
    // `factures_soldee_a` (V10), immuable. Géré par la base → lecture seule côté JPA.
    @Column(name = "soldee_a", insertable = false, updatable = false)
    private Instant soldeeA;

    protected Facture() {
    }

    public Facture(String numero, String etablissementRef, String patientRef, int exercice, String devise,
                   long sousTotalHt, long totalRemises, long totalTva, long montantTtc,
                   TypePriseEnCharge couvertureType, Integer couvertureTaux,
                   long montantCouvert, long resteAPayer, FactureStatut statut, String hashIntegrite) {
        this.numero = numero;
        this.etablissementRef = etablissementRef;
        this.patientRef = patientRef;
        this.exercice = exercice;
        this.devise = devise;
        this.sousTotalHt = sousTotalHt;
        this.totalRemises = totalRemises;
        this.totalTva = totalTva;
        this.montantTtc = montantTtc;
        this.couvertureType = couvertureType;
        this.couvertureTaux = couvertureTaux;
        this.montantCouvert = montantCouvert;
        this.resteAPayer = resteAPayer;
        this.montantRegle = 0;
        this.statut = statut;
        this.hashIntegrite = hashIntegrite;
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

    public String getPatientRef() {
        return patientRef;
    }

    public int getExercice() {
        return exercice;
    }

    public String getDevise() {
        return devise;
    }

    public long getSousTotalHt() {
        return sousTotalHt;
    }

    public long getTotalRemises() {
        return totalRemises;
    }

    public long getTotalTva() {
        return totalTva;
    }

    public long getMontantTtc() {
        return montantTtc;
    }

    public TypePriseEnCharge getCouvertureType() {
        return couvertureType;
    }

    public Integer getCouvertureTaux() {
        return couvertureTaux;
    }

    public long getMontantCouvert() {
        return montantCouvert;
    }

    public long getResteAPayer() {
        return resteAPayer;
    }

    public long getMontantRegle() {
        return montantRegle;
    }

    public void setMontantRegle(long montantRegle) {
        this.montantRegle = montantRegle;
    }

    public FactureStatut getStatut() {
        return statut;
    }

    public void setStatut(FactureStatut statut) {
        this.statut = statut;
    }

    public String getHashIntegrite() {
        return hashIntegrite;
    }

    public int getVersionNumero() {
        return versionNumero;
    }

    public void setVersionNumero(int versionNumero) {
        this.versionNumero = versionNumero;
    }

    public UUID getOrigineFactureId() {
        return origineFactureId;
    }

    public void setOrigineFactureId(UUID origineFactureId) {
        this.origineFactureId = origineFactureId;
    }

    public UUID getRemplaceeParId() {
        return remplaceeParId;
    }

    public void setRemplaceeParId(UUID remplaceeParId) {
        this.remplaceeParId = remplaceeParId;
    }

    public String getSignature() {
        return signature;
    }

    public String getSignaturePubkey() {
        return signaturePubkey;
    }

    public String getSignatureAlgo() {
        return signatureAlgo;
    }

    /** Appose le sceau avant persistance (updatable=false). */
    public void apposerSignature(String signature, String pubkey, String algo) {
        this.signature = signature;
        this.signaturePubkey = pubkey;
        this.signatureAlgo = algo;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }

    public Instant getUpdatedAt() {
        return updatedAt;
    }

    /** Instant de solde (passage à PAYEE), immuable. Null tant que la facture n'est pas PAYEE. */
    public Instant getSoldeeA() {
        return soldeeA;
    }
}
