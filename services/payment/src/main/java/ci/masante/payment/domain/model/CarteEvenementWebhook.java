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
import java.util.UUID;

/**
 * Événement de webhook reçu (CDC_06 §7.3). {@code UNIQUE(psp, evenement_id)} = idempotence AU NIVEAU BASE :
 * un rejeu du même événement viole la contrainte → « déjà traité ». {@code chargeUtileMasquee} = snapshot
 * JSONB MASQUÉ (jamais de donnée sensible).
 */
@Entity
@Table(name = "carte_evenements_webhook")
public class CarteEvenementWebhook {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "psp", nullable = false, updatable = false)
    private String psp;

    @Column(name = "evenement_id", nullable = false, updatable = false)
    private String evenementId;

    @Column(name = "type", nullable = false, updatable = false)
    private String type;

    @CreationTimestamp
    @Column(name = "recu_le", nullable = false, updatable = false)
    private Instant recuLe;

    @Column(name = "traite_le")
    private Instant traiteLe;

    @Column(name = "statut_traitement", nullable = false)
    private String statutTraitement;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "charge_utile_masquee", nullable = false)
    private String chargeUtileMasquee;

    // ------------------------------------------------------------------------------------------
    // Colonnes ajoutées par le lot 7 (V17). Elles servent le webhook GeniusPay et restent NULLES
    // pour les événements carte : leur inventer une valeur serait un mensonge d'archive.
    // La table garde son nom d'origine (dette de nommage assumée, cf. V17) ; le fait qui compte est
    // porté par `psp`, présent depuis P5.4a.
    // ------------------------------------------------------------------------------------------

    /** SHA-256 du corps brut — second filet d'idempotence si le payload ne porte pas de champ `id`. */
    @Column(name = "empreinte_corps", updatable = false)
    private String empreinteCorps;

    /** Horodatage DÉCLARÉ par le prestataire, à ne pas confondre avec {@code recuLe}, qui est le nôtre. */
    @Column(name = "horodatage_declare", updatable = false)
    private Long horodatageDeclare;

    @Column(name = "environnement", updatable = false)
    private String environnement;

    @Column(name = "signature_valide", updatable = false)
    private Boolean signatureValide;

    @Column(name = "motif_rejet")
    private String motifRejet;

    @Column(name = "numero_tentative", updatable = false)
    private Integer numeroTentative;

    @Column(name = "reference_passerelle")
    private String referencePasserelle;

    @Column(name = "adresse_ip", updatable = false)
    private String adresseIp;

    /**
     * Corps intégral tel que reçu. C'est la seule forme qui permette de rejouer une vérification de
     * signature lors d'un litige : un corps normalisé ne prouverait plus rien. Aucune donnée
     * personnelle ne peut s'y trouver, parce que l'initiation n'en envoie aucune au prestataire.
     */
    @Column(name = "corps_brut", updatable = false)
    private String corpsBrut;

    protected CarteEvenementWebhook() {
    }

    public CarteEvenementWebhook(String psp, String evenementId, String type, String statutTraitement,
                                 String chargeUtileMasquee) {
        this.psp = psp;
        this.evenementId = evenementId;
        this.type = type;
        this.statutTraitement = statutTraitement;
        this.chargeUtileMasquee = chargeUtileMasquee;
    }

    /**
     * Forme complète, utilisée par le webhook GeniusPay (lot 7). Le constructeur d'origine est
     * conservé intact : le module carte n'a pas été touché.
     */
    public CarteEvenementWebhook(String psp, String evenementId, String type, String statutTraitement,
                                 String chargeUtileMasquee, String empreinteCorps, Long horodatageDeclare,
                                 String environnement, Boolean signatureValide, String motifRejet,
                                 Integer numeroTentative, String referencePasserelle, String adresseIp,
                                 String corpsBrut) {
        this(psp, evenementId, type, statutTraitement, chargeUtileMasquee);
        this.empreinteCorps = empreinteCorps;
        this.horodatageDeclare = horodatageDeclare;
        this.environnement = environnement;
        this.signatureValide = signatureValide;
        this.motifRejet = motifRejet;
        this.numeroTentative = numeroTentative;
        this.referencePasserelle = referencePasserelle;
        this.adresseIp = adresseIp;
        this.corpsBrut = corpsBrut;
    }

    public String getEmpreinteCorps() {
        return empreinteCorps;
    }

    public Long getHorodatageDeclare() {
        return horodatageDeclare;
    }

    public String getEnvironnement() {
        return environnement;
    }

    public Boolean getSignatureValide() {
        return signatureValide;
    }

    public String getMotifRejet() {
        return motifRejet;
    }

    public void setMotifRejet(String motifRejet) {
        this.motifRejet = motifRejet;
    }

    public Integer getNumeroTentative() {
        return numeroTentative;
    }

    public String getReferencePasserelle() {
        return referencePasserelle;
    }

    public void setReferencePasserelle(String referencePasserelle) {
        this.referencePasserelle = referencePasserelle;
    }

    public String getAdresseIp() {
        return adresseIp;
    }

    public String getCorpsBrut() {
        return corpsBrut;
    }

    public void marquerTraite(String statutTraitement, Instant quand) {
        this.statutTraitement = statutTraitement;
        this.traiteLe = quand;
    }

    public UUID getId() {
        return id;
    }

    public String getPsp() {
        return psp;
    }

    public String getEvenementId() {
        return evenementId;
    }

    public String getType() {
        return type;
    }

    public Instant getRecuLe() {
        return recuLe;
    }

    public Instant getTraiteLe() {
        return traiteLe;
    }

    public String getStatutTraitement() {
        return statutTraitement;
    }

    public String getChargeUtileMasquee() {
        return chargeUtileMasquee;
    }
}
