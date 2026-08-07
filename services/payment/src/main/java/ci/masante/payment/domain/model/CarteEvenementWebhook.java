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
