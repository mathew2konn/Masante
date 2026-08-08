package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UuidGenerator;

import java.time.Instant;
import java.util.UUID;

/**
 * Tentative de décaissement d'un relevé (CDC_06 §11, P5.5b-2). Une ligne par tentative : bras LOCAL du
 * rapprochement à 2 sources de S11.x (registre local ⇄ vérité passerelle). Ne porte JAMAIS la référence
 * de destination en clair — seulement {@code referencePasserelle} (réf opérateur simulée).
 *
 * <p>Mutations bornées au suivi d'UNE tentative EN_COURS : {@code marquerExecute}/{@code marquerEchoue}
 * (transition terminale). Aucun autre setter → immuabilité des montants/clé/acteur.</p>
 */
@Entity
@Table(name = "reversement_decaissement")
public class DecaissementReversement {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "releve_id", nullable = false, updatable = false)
    private UUID releveId;

    @Column(name = "destination_id", nullable = false, updatable = false)
    private UUID destinationId;

    @Column(name = "reference_passerelle")
    private String referencePasserelle;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private DecaissementStatut statut;

    @Column(name = "montant_net", nullable = false, updatable = false)
    private long montantNet;

    @Column(name = "frais", nullable = false)
    private long frais;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "idempotency_key", nullable = false, updatable = false)
    private String idempotencyKey;

    @Column(name = "motif_echec")
    private String motifEchec;

    @Column(name = "cree_par", nullable = false, updatable = false)
    private String creePar;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    protected DecaissementReversement() {
    }

    /** Naît EN_COURS (versement engagé auprès de la passerelle). */
    public DecaissementReversement(UUID releveId, UUID destinationId, long montantNet, String devise,
                                   String idempotencyKey, String creePar) {
        this.releveId = releveId;
        this.destinationId = destinationId;
        this.montantNet = montantNet;
        this.frais = 0L;
        this.devise = devise;
        this.idempotencyKey = idempotencyKey;
        this.creePar = creePar;
        this.statut = DecaissementStatut.EN_COURS;
        this.majLe = Instant.now();
    }

    /** Versement confirmé par la passerelle (EN_COURS → EXECUTE) : réf opérateur + frais rapportés. */
    public void marquerExecute(String referencePasserelle, long frais) {
        this.statut = DecaissementStatut.EXECUTE;
        this.referencePasserelle = referencePasserelle;
        this.frais = frais;
        this.majLe = Instant.now();
    }

    /** Versement refusé par la passerelle (EN_COURS → ECHOUE) : rien n'est parti. */
    public void marquerEchoue(String referencePasserelle, String motif) {
        this.statut = DecaissementStatut.ECHOUE;
        this.referencePasserelle = referencePasserelle;
        this.motifEchec = motif;
        this.majLe = Instant.now();
    }

    public UUID getId() {
        return id;
    }

    public UUID getReleveId() {
        return releveId;
    }

    public UUID getDestinationId() {
        return destinationId;
    }

    public String getReferencePasserelle() {
        return referencePasserelle;
    }

    public DecaissementStatut getStatut() {
        return statut;
    }

    public long getMontantNet() {
        return montantNet;
    }

    public long getFrais() {
        return frais;
    }

    public String getDevise() {
        return devise;
    }

    public String getIdempotencyKey() {
        return idempotencyKey;
    }

    public String getMotifEchec() {
        return motifEchec;
    }

    public String getCreePar() {
        return creePar;
    }

    public Instant getCreeLe() {
        return creeLe;
    }

    public Instant getMajLe() {
        return majLe;
    }
}
