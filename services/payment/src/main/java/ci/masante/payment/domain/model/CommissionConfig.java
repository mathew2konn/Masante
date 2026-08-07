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
 * Taux de commission plateforme historisé (CDC_06 §11). Append-only + temporalisé :
 * {@code etablissement_ref} NULL = taux par défaut de la plateforme. Un taux « ouvert » a
 * {@code validAu == null}. Seule la clôture ({@code validAu}) est modifiable ; le non-chevauchement
 * temporel et l'append-only sont garantis par {@code ServiceCommissionConfig} (+ index unique partiel
 * en base). Taux en points de base entiers (250 = 2,50 %).
 */
@Entity
@Table(name = "reversement_commission_config")
public class CommissionConfig {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "etablissement_ref", updatable = false)
    private String etablissementRef;

    @Column(name = "taux_bps", nullable = false, updatable = false)
    private int tauxBps;

    @Column(name = "valide_du", nullable = false, updatable = false)
    private Instant valideDu;

    @Column(name = "valide_au")
    private Instant valideAu;

    @Column(name = "motif", nullable = false, updatable = false)
    private String motif;

    @Column(name = "remplace_config_id", updatable = false)
    private UUID remplaceConfigId;

    @Column(name = "cree_par", nullable = false, updatable = false)
    private String creePar;

    @CreationTimestamp
    @Column(name = "cree_a", nullable = false, updatable = false)
    private Instant creeA;

    protected CommissionConfig() {
    }

    public CommissionConfig(String etablissementRef, int tauxBps, Instant valideDu, String motif,
                            UUID remplaceConfigId, String creePar) {
        this.etablissementRef = etablissementRef;
        this.tauxBps = tauxBps;
        this.valideDu = valideDu;
        this.motif = motif;
        this.remplaceConfigId = remplaceConfigId;
        this.creePar = creePar;
    }

    /** Clôture le taux (seule mutation autorisée). */
    public void cloturer(Instant valideAu) {
        this.valideAu = valideAu;
    }

    public UUID getId() {
        return id;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public int getTauxBps() {
        return tauxBps;
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

    public UUID getRemplaceConfigId() {
        return remplaceConfigId;
    }

    public String getCreePar() {
        return creePar;
    }

    public Instant getCreeA() {
        return creeA;
    }
}
