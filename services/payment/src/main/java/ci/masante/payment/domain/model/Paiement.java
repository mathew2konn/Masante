package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import jakarta.persistence.Transient;
import jakarta.persistence.Version;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UpdateTimestamp;
import org.hibernate.annotations.UuidGenerator;
import org.springframework.data.domain.AfterDomainEventPublication;
import org.springframework.data.domain.DomainEvents;

import java.time.Instant;
import java.util.ArrayList;
import java.util.Collection;
import java.util.List;
import java.util.UUID;

/**
 * Transaction de paiement (CDC_06 §4.3). FCFA = entier. Le statut n'évolue que via la machine à
 * états (§4.2), consignée dans {@code payment_transitions}, et chaque étape est auditée.
 *
 * <p><b>UN SEUL POINT D'ACCROCHE POUR LE CANAL INTERNE</b> (lot 6). Deux services font aujourd'hui
 * passer un paiement à son issue : {@code ServicePaiement} (mobile money) et {@code ServiceCarte}
 * (projection du sous-état carte sur la machine partagée). Les accrocher séparément aurait produit
 * une garantie à deux endroits — donc une garantie qu'un troisième chemin, écrit demain, peut
 * ignorer sans que rien ne le signale.</p>
 *
 * <p>L'accroche vit donc sur l'agrégat : {@link #setStatut} est le passage OBLIGÉ de tous, et
 * l'événement part au {@code save()} du repository via {@link DomainEvents} (Spring Data, publication
 * SYNCHRONE dans la transaction en cours — ce que l'Outbox exige, CDC_03 §8). Aucune dépendance
 * nouvelle, aucun listener JPA, aucune duplication de la règle « qu'est-ce qu'un état terminal ».</p>
 */
@Entity
@Table(name = "payments")
public class Paiement {

    @Id
    @UuidGenerator
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "idempotency_key", nullable = false, updatable = false)
    private String idempotencyKey;

    @Column(name = "correlation_id", updatable = false)
    private String correlationId;

    @Column(name = "montant", nullable = false, updatable = false)
    private long montant;

    @Column(name = "devise", nullable = false, updatable = false)
    private String devise;

    @Column(name = "canal", nullable = false, updatable = false)
    private String canal;

    @Enumerated(EnumType.STRING)
    @Column(name = "objet", nullable = false, updatable = false)
    private ObjetPaiement objet;

    @Column(name = "telephone_masque", updatable = false)
    private String telephoneMasque;

    @Column(name = "etablissement_ref", updatable = false)
    private String etablissementRef;

    @Column(name = "patient_ref", updatable = false)
    private String patientRef;

    @Enumerated(EnumType.STRING)
    @Column(name = "statut", nullable = false)
    private PaiementStatut statut;

    @Column(name = "provider_ref")
    private String providerRef;

    /** Facture soldée par ce paiement (null si paiement hors facture). Renseigné après confirmation. */
    @Column(name = "facture_id")
    private UUID factureId;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @UpdateTimestamp
    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    @Column(name = "confirmed_at")
    private Instant confirmedAt;

    /**
     * Événements en attente de publication. {@code @Transient} : ce n'est pas un état persisté, c'est
     * une intention à émettre au prochain {@code save()}. Le champ est vidé aussitôt publié — sans
     * quoi une seconde sauvegarde de la même entité (il y en a, ex. la pose de {@code confirmedAt})
     * republierait le même événement, et le partenaire serait notifié deux fois du même fait.
     */
    @Transient
    private final transient List<Object> evenements = new ArrayList<>();

    protected Paiement() {
    }

    public Paiement(String idempotencyKey, String correlationId, long montant, String devise,
                    String canal, ObjetPaiement objet, String telephoneMasque,
                    String etablissementRef, String patientRef) {
        this.idempotencyKey = idempotencyKey;
        this.correlationId = correlationId;
        this.montant = montant;
        this.devise = devise;
        this.canal = canal;
        this.objet = objet;
        this.telephoneMasque = telephoneMasque;
        this.etablissementRef = etablissementRef;
        this.patientRef = patientRef;
        this.statut = PaiementStatut.INITIATED;
    }

    public UUID getId() {
        return id;
    }

    public String getIdempotencyKey() {
        return idempotencyKey;
    }

    public String getCorrelationId() {
        return correlationId;
    }

    public long getMontant() {
        return montant;
    }

    public String getDevise() {
        return devise;
    }

    public String getCanal() {
        return canal;
    }

    public ObjetPaiement getObjet() {
        return objet;
    }

    public String getTelephoneMasque() {
        return telephoneMasque;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public String getPatientRef() {
        return patientRef;
    }

    public PaiementStatut getStatut() {
        return statut;
    }

    /**
     * Pose le nouvel état — validé en amont par la machine à états, jamais ici — et, si cet état est
     * terminal, retient un événement à publier au prochain {@code save()} (lot 6, canal interne).
     *
     * <p>La garde {@code statut != nouveau} est le garde-fou de la répétition : repasser un paiement
     * dans l'état qu'il occupe déjà n'est pas un fait nouveau et ne doit rien annoncer.</p>
     */
    public void setStatut(PaiementStatut statut) {
        boolean transitionReelle = this.statut != statut;
        this.statut = statut;
        if (transitionReelle && statut != null && statut.estTerminal()) {
            evenements.add(new TransitionTerminaleEvenement(
                    id, correlationId, montant, devise, statut, Instant.now()));
        }
    }

    /** Publié par Spring Data au {@code save()} du repository — donc dans la transaction en cours. */
    @DomainEvents
    Collection<Object> evenements() {
        return List.copyOf(evenements);
    }

    @AfterDomainEventPublication
    void viderEvenements() {
        evenements.clear();
    }

    public String getProviderRef() {
        return providerRef;
    }

    public void setProviderRef(String providerRef) {
        this.providerRef = providerRef;
    }

    public UUID getFactureId() {
        return factureId;
    }

    public void setFactureId(UUID factureId) {
        this.factureId = factureId;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }

    public Instant getUpdatedAt() {
        return updatedAt;
    }

    public Instant getConfirmedAt() {
        return confirmedAt;
    }

    public void setConfirmedAt(Instant confirmedAt) {
        this.confirmedAt = confirmedAt;
    }
}
