package ci.masante.payment.domain.model;

import ci.masante.payment.domain.notification.StatutNotification;
import ci.masante.payment.domain.notification.TypeNotification;
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
import java.util.UUID;

/**
 * Ligne d'OUTBOX de notification (CDC_03 §8 : Outbox Pattern — écrite dans la MÊME transaction que le
 * changement métier, jamais publiée avant le commit). Un relais la livre ensuite via un {@link
 * ci.masante.payment.domain.notification.EnvoiNotification} (SIMULÉ, FT5). {@code EN_ATTENTE → ENVOYEE|ECHOUEE}.
 */
@Entity
@Table(name = "notifications_outbox")
public class NotificationSortie {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Enumerated(EnumType.STRING)
    @Column(name = "type", nullable = false, updatable = false)
    private TypeNotification type;

    @Column(name = "agregat_type", nullable = false, updatable = false)
    private String agregatType;

    @Column(name = "agregat_id", nullable = false, updatable = false)
    private UUID agregatId;

    @Column(name = "destinataire_ref", nullable = false, updatable = false)
    private String destinataireRef;

    @Column(name = "canal_souhaite", nullable = false, updatable = false)
    private String canalSouhaite;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "charge_utile", nullable = false, updatable = false)
    private String chargeUtile;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private StatutNotification statut;

    @Column(name = "canal_livraison")
    private String canalLivraison;

    @Column(name = "detail")
    private String detail;

    @Column(name = "tentatives", nullable = false)
    private int tentatives;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @Column(name = "traite_le")
    private Instant traiteLe;

    protected NotificationSortie() {
    }

    public NotificationSortie(TypeNotification type, String agregatType, UUID agregatId, String destinataireRef,
                              String canalSouhaite, String chargeUtile) {
        this.type = type;
        this.agregatType = agregatType;
        this.agregatId = agregatId;
        this.destinataireRef = destinataireRef;
        this.canalSouhaite = canalSouhaite;
        this.chargeUtile = chargeUtile;
        this.statut = StatutNotification.EN_ATTENTE;
    }

    public void marquerEnvoyee(String canal, Instant quand) {
        this.statut = StatutNotification.ENVOYEE;
        this.canalLivraison = canal;
        this.tentatives++;
        this.traiteLe = quand;
    }

    public void marquerEchouee(String detail, Instant quand) {
        this.statut = StatutNotification.ECHOUEE;
        this.detail = detail;
        this.tentatives++;
        this.traiteLe = quand;
    }

    public boolean estEnAttente() {
        return statut == StatutNotification.EN_ATTENTE;
    }

    public UUID getId() {
        return id;
    }

    public TypeNotification getType() {
        return type;
    }

    public String getAgregatType() {
        return agregatType;
    }

    public UUID getAgregatId() {
        return agregatId;
    }

    public String getDestinataireRef() {
        return destinataireRef;
    }

    public String getCanalSouhaite() {
        return canalSouhaite;
    }

    public String getChargeUtile() {
        return chargeUtile;
    }

    public StatutNotification getStatut() {
        return statut;
    }

    public String getCanalLivraison() {
        return canalLivraison;
    }

    public String getDetail() {
        return detail;
    }

    public int getTentatives() {
        return tentatives;
    }

    public Instant getCreeLe() {
        return creeLe;
    }

    public Instant getTraiteLe() {
        return traiteLe;
    }
}
