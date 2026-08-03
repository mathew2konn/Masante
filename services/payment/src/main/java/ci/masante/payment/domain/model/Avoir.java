package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.util.UUID;

/**
 * Avoir / note de crédit (CDC_06 §7.1). Émis lors d'une correction (§7.5) ou d'une annulation de
 * facture ; son montant neutralise le TTC de la facture d'origine. Immuable ; signable (§7.4).
 */
@Entity
@Table(name = "avoirs")
public class Avoir {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "numero", nullable = false, updatable = false)
    private String numero;

    @Column(name = "facture_id", nullable = false, updatable = false)
    private UUID factureId;

    @Column(name = "etablissement_ref", nullable = false, updatable = false)
    private String etablissementRef;

    @Column(name = "exercice", nullable = false, updatable = false)
    private int exercice;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "motif", nullable = false, updatable = false)
    private String motif;

    @Column(name = "hash_integrite", nullable = false, updatable = false)
    private String hashIntegrite;

    @Column(name = "signature", updatable = false)
    private String signature;

    @Column(name = "signature_pubkey", updatable = false)
    private String signaturePubkey;

    @Column(name = "signature_algo", updatable = false)
    private String signatureAlgo;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    protected Avoir() {
    }

    public Avoir(String numero, UUID factureId, String etablissementRef, int exercice,
                 long montant, String motif, String hashIntegrite) {
        this.numero = numero;
        this.factureId = factureId;
        this.etablissementRef = etablissementRef;
        this.exercice = exercice;
        this.montant = montant;
        this.motif = motif;
        this.hashIntegrite = hashIntegrite;
    }

    public void apposerSignature(String signature, String pubkey, String algo) {
        this.signature = signature;
        this.signaturePubkey = pubkey;
        this.signatureAlgo = algo;
    }

    public UUID getId() {
        return id;
    }

    public String getNumero() {
        return numero;
    }

    public UUID getFactureId() {
        return factureId;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public int getExercice() {
        return exercice;
    }

    public long getMontant() {
        return montant;
    }

    public String getMotif() {
        return motif;
    }

    public String getHashIntegrite() {
        return hashIntegrite;
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

    public Instant getCreatedAt() {
        return createdAt;
    }
}
