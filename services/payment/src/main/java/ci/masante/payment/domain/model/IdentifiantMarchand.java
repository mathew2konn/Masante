package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import jakarta.persistence.Version;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UpdateTimestamp;

import java.time.Instant;
import java.util.UUID;

/**
 * Identifiants marchands d'un établissement chez un prestataire (montage A, §6.2).
 *
 * <p><b>Ce sont les secrets d'un tiers.</b> La clé secrète et le secret webhook sont stockés
 * <b>chiffrés</b> (AES-256-GCM, {@code GestionnaireSecretsMarchand}) et ne sortent jamais de cette
 * classe autrement que par un déchiffrement explicite. Le {@code toString()} est réécrit : une entité
 * portant des secrets finit tôt ou tard dans un message d'erreur ou une trace, et la valeur par
 * défaut de Java y aurait mis les champs.</p>
 *
 * <p>{@code slug} — identifiant <b>opaque et aléatoire</b> présent dans l'URL de rappel. Il ne dit
 * rien de l'établissement : une URL construite sur {@code etablissement_ref} serait énumérable et
 * révélerait la liste des partenaires. Il ne fait que <b>sélectionner</b> le secret candidat ; c'est
 * la vérification HMAC qui décide.</p>
 */
@Entity
@Table(name = "identifiants_marchand")
public class IdentifiantMarchand {

    /**
     * Identifiant <b>toujours fourni par l'appelant</b>, jamais généré par Hibernate.
     *
     * <p>Ce n'est pas un détail de style : cet identifiant entre dans l'AAD du chiffrement, donc il
     * doit être connu <b>avant</b> que le secret ne soit chiffré. Une annotation {@code @UuidGenerator}
     * en poserait un autre au moment du {@code persist} — le blob serait alors scellé sur un
     * identifiant, et relu sur un autre. Le déchiffrement échouerait, et il a effectivement échoué au
     * premier appel réel : l'AAD a attrapé le défaut au lieu de laisser passer une clé mal liée.</p>
     */
    @Id
    @Column(name = "id", updatable = false, nullable = false)
    private UUID id;

    @Column(name = "etablissement_ref", nullable = false, updatable = false)
    private String etablissementRef;

    @Column(name = "psp", nullable = false, updatable = false)
    private String psp;

    @Column(name = "slug", nullable = false, updatable = false)
    private String slug;

    @Column(name = "cle_publique", nullable = false)
    private String clePublique;

    @Column(name = "cle_secrete_chiffree", nullable = false)
    private byte[] cleSecreteChiffree;

    @Column(name = "cle_secrete_nonce", nullable = false)
    private byte[] cleSecreteNonce;

    @Column(name = "secret_webhook_chiffre")
    private byte[] secretWebhookChiffre;

    @Column(name = "secret_webhook_nonce")
    private byte[] secretWebhookNonce;

    @Column(name = "cle_version", nullable = false)
    private short cleVersion;

    @Column(name = "environnement", nullable = false)
    private String environnement;

    @Column(name = "actif", nullable = false)
    private boolean actif;

    @Column(name = "date_rotation")
    private Instant dateRotation;

    @CreationTimestamp
    @Column(name = "cree_le", nullable = false, updatable = false)
    private Instant creeLe;

    @UpdateTimestamp
    @Column(name = "maj_le", nullable = false)
    private Instant majLe;

    @Version
    @Column(name = "version", nullable = false)
    private long version;

    protected IdentifiantMarchand() {
    }

    /**
     * L'identifiant est fourni par l'appelant, jamais laissé à Hibernate : il entre dans l'AAD du
     * chiffrement, donc il doit exister AVANT que le secret ne soit chiffré. Même contrainte, même
     * geste que {@code DestinationReversement} (P5.5b-1).
     */
    public IdentifiantMarchand(UUID id, String etablissementRef, String psp, String slug, String clePublique,
                               byte[] cleSecreteChiffree, byte[] cleSecreteNonce, short cleVersion,
                               String environnement) {
        this.id = id;
        this.etablissementRef = etablissementRef;
        this.psp = psp;
        this.slug = slug;
        this.clePublique = clePublique;
        this.cleSecreteChiffree = cleSecreteChiffree;
        this.cleSecreteNonce = cleSecreteNonce;
        this.cleVersion = cleVersion;
        this.environnement = environnement;
        this.actif = true;
    }

    /**
     * Le secret webhook n'est renvoyé par GeniusPay qu'à la <b>création</b> du webhook, une seule
     * fois. Le perdre impose d'en recréer un — d'où l'écriture séparée de la création de la ligne.
     */
    public void poserSecretWebhook(byte[] chiffre, byte[] nonce) {
        this.secretWebhookChiffre = chiffre;
        this.secretWebhookNonce = nonce;
        this.dateRotation = Instant.now();
    }

    public boolean aUnSecretWebhook() {
        return secretWebhookChiffre != null && secretWebhookNonce != null;
    }

    public UUID getId() {
        return id;
    }

    public String getEtablissementRef() {
        return etablissementRef;
    }

    public String getPsp() {
        return psp;
    }

    public String getSlug() {
        return slug;
    }

    public String getClePublique() {
        return clePublique;
    }

    public byte[] getCleSecreteChiffree() {
        return cleSecreteChiffree;
    }

    public byte[] getCleSecreteNonce() {
        return cleSecreteNonce;
    }

    public byte[] getSecretWebhookChiffre() {
        return secretWebhookChiffre;
    }

    public byte[] getSecretWebhookNonce() {
        return secretWebhookNonce;
    }

    public short getCleVersion() {
        return cleVersion;
    }

    public String getEnvironnement() {
        return environnement;
    }

    public boolean isActif() {
        return actif;
    }

    public void setActif(boolean actif) {
        this.actif = actif;
    }

    public Instant getDateRotation() {
        return dateRotation;
    }

    /**
     * Masquage explicite (§6.2.3). Ne cite ni la clé publique, ni le slug : le premier est public mais
     * identifie le marchand, le second est le sélecteur du secret — aucun des deux n'a à traîner dans
     * une trace d'exception.
     */
    @Override
    public String toString() {
        return "IdentifiantMarchand{etablissement=" + etablissementRef + ", psp=" + psp
                + ", cles=***, secretWebhook=***}";
    }
}
