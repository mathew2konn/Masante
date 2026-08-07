package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;

import java.time.Instant;
import java.util.UUID;

/**
 * Destination de reversement d'un établissement (CDC_06 §11), chiffrée et historisée. L'{@code id} est
 * généré <b>côté application</b> (il entre dans l'AAD du chiffrement, donc requis avant l'INSERT). Une
 * seule destination active ({@code valideAu == null}) par établissement. Append-only : seule la clôture
 * ({@code valideAu}) est modifiable.
 */
@Entity
@Table(name = "reversement_destination")
public class DestinationReversement {

    @Id
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "etablissement_ref", nullable = false, updatable = false)
    private String etablissementRef;

    @Enumerated(EnumType.STRING)
    @Column(name = "type", nullable = false, updatable = false)
    private TypeDestination type;

    @Column(name = "ref_chiffree", nullable = false, updatable = false)
    private byte[] refChiffree;

    @Column(name = "nonce", nullable = false, updatable = false)
    private byte[] nonce;

    @Column(name = "cle_version", nullable = false, updatable = false)
    private short cleVersion;

    @Column(name = "empreinte", nullable = false, updatable = false)
    private String empreinte;

    @Column(name = "empreinte_version", nullable = false, updatable = false)
    private short empreinteVersion;

    @Column(name = "libelle", nullable = false, updatable = false)
    private String libelle;

    @Column(name = "valide_du", nullable = false, updatable = false)
    private Instant valideDu;

    @Column(name = "valide_au")
    private Instant valideAu;

    @Column(name = "motif", nullable = false, updatable = false)
    private String motif;

    @Column(name = "remplace_id", updatable = false)
    private UUID remplaceId;

    @Column(name = "cree_par", nullable = false, updatable = false)
    private String creePar;

    @CreationTimestamp
    @Column(name = "cree_a", nullable = false, updatable = false)
    private Instant creeA;

    protected DestinationReversement() {
    }

    public DestinationReversement(UUID id, String etablissementRef, TypeDestination type, byte[] refChiffree,
                                  byte[] nonce, short cleVersion, String empreinte, short empreinteVersion,
                                  String libelle, Instant valideDu, String motif, UUID remplaceId, String creePar) {
        this.id = id;
        this.etablissementRef = etablissementRef;
        this.type = type;
        this.refChiffree = refChiffree;
        this.nonce = nonce;
        this.cleVersion = cleVersion;
        this.empreinte = empreinte;
        this.empreinteVersion = empreinteVersion;
        this.libelle = libelle;
        this.valideDu = valideDu;
        this.motif = motif;
        this.remplaceId = remplaceId;
        this.creePar = creePar;
    }

    /** Clôture la destination (seule mutation autorisée). */
    public void cloturer(Instant valideAu) {
        this.valideAu = valideAu;
    }

    public UUID getId() {
        return id;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public TypeDestination getType() {
        return type;
    }

    public byte[] getRefChiffree() {
        return refChiffree;
    }

    public byte[] getNonce() {
        return nonce;
    }

    public short getCleVersion() {
        return cleVersion;
    }

    public String getEmpreinte() {
        return empreinte;
    }

    public short getEmpreinteVersion() {
        return empreinteVersion;
    }

    public String getLibelle() {
        return libelle;
    }

    public Instant getValideDu() {
        return valideDu;
    }

    public Instant getValideAu() {
        return valideAu;
    }

    public String getMotif() {
        return motif;
    }

    public UUID getRemplaceId() {
        return remplaceId;
    }

    public String getCreePar() {
        return creePar;
    }

    public Instant getCreeA() {
        return creeA;
    }
}
