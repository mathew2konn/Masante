package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.UuidGenerator;

import java.util.UUID;

/** Ligne d'une facture (montants FCFA calculés par {@code MoteurFacturation}). */
@Entity
@Table(name = "facture_lignes")
public class FactureLigne {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "facture_id", nullable = false, updatable = false)
    private UUID factureId;

    @Column(name = "ordre", nullable = false, updatable = false)
    private int ordre;

    @Column(name = "libelle", nullable = false, updatable = false)
    private String libelle;

    @Column(name = "quantite", nullable = false, updatable = false)
    private int quantite;

    @Column(name = "prix_unitaire", nullable = false, updatable = false)
    private long prixUnitaire;

    @Column(name = "remise", nullable = false, updatable = false)
    private long remise;

    @Column(name = "taux_tva", nullable = false, updatable = false)
    private int tauxTva;

    @Column(name = "montant_ht", nullable = false, updatable = false)
    private long montantHt;

    @Column(name = "montant_tva", nullable = false, updatable = false)
    private long montantTva;

    @Column(name = "montant_ttc", nullable = false, updatable = false)
    private long montantTtc;

    protected FactureLigne() {
    }

    public FactureLigne(UUID factureId, int ordre, String libelle, int quantite, long prixUnitaire,
                        long remise, int tauxTva, long montantHt, long montantTva, long montantTtc) {
        this.factureId = factureId;
        this.ordre = ordre;
        this.libelle = libelle;
        this.quantite = quantite;
        this.prixUnitaire = prixUnitaire;
        this.remise = remise;
        this.tauxTva = tauxTva;
        this.montantHt = montantHt;
        this.montantTva = montantTva;
        this.montantTtc = montantTtc;
    }

    public UUID getId() {
        return id;
    }

    public UUID getFactureId() {
        return factureId;
    }

    public int getOrdre() {
        return ordre;
    }

    public String getLibelle() {
        return libelle;
    }

    public int getQuantite() {
        return quantite;
    }

    public long getPrixUnitaire() {
        return prixUnitaire;
    }

    public long getRemise() {
        return remise;
    }

    public int getTauxTva() {
        return tauxTva;
    }

    public long getMontantHt() {
        return montantHt;
    }

    public long getMontantTva() {
        return montantTva;
    }

    public long getMontantTtc() {
        return montantTtc;
    }
}
