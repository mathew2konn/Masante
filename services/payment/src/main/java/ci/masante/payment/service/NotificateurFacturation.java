package ci.masante.payment.service;

import ci.masante.payment.domain.model.TransitionTerminaleEvenement;
import ci.masante.payment.domain.notification.TypeNotification;
import org.springframework.context.event.EventListener;
import org.springframework.stereotype.Component;

import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Enfile une notification vers Laravel à chaque transition terminale d'un paiement (lot 6).
 *
 * <p>Écoute l'événement publié par l'agrégat {@code Paiement} lui-même : ce composant n'a donc
 * connaissance ni de {@code ServicePaiement}, ni de {@code ServiceCarte}, ni d'aucun chemin futur —
 * il apprend qu'un paiement a une issue, pas comment il l'a obtenue.</p>
 *
 * <p>{@link EventListener} et non {@code @TransactionalEventListener} : la ligne doit être écrite
 * DANS la transaction du changement métier, jamais après son commit (Outbox, CDC_03 §8). Un envoi
 * post-commit rouvrirait exactement la fenêtre que l'Outbox existe pour fermer — le paiement
 * committé, la notification perdue.</p>
 *
 * <p><b>Ce composant n'envoie rien</b> : il enfile. La livraison est l'affaire du relais existant.</p>
 */
@Component
public class NotificateurFacturation {

    /**
     * Destinataire système, consigné pour que l'outbox reste lisible : la colonne est obligatoire et
     * répond à « à qui cette ligne était-elle destinée ? », y compris des mois plus tard.
     */
    static final String DESTINATAIRE = "LARAVEL-MASANTE";

    private final ServiceNotifications notifications;

    public NotificateurFacturation(ServiceNotifications notifications) {
        this.notifications = notifications;
    }

    @EventListener
    public void surTransitionTerminale(TransitionTerminaleEvenement evenement) {
        notifications.emettre(
                TypeNotification.PAIEMENT_NOTIFICATION_LARAVEL,
                "paiement",
                evenement.paiementId(),
                DESTINATAIRE,
                charge(evenement));
    }

    /**
     * Charge utile : STRICTEMENT ce que le service sait.
     *
     * <p>Le statut part dans le vocabulaire de {@code PaiementStatut}, l'enum répliqué à l'identique
     * dans {@code @masante/shared} et déjà connu des deux côtés. Le traduire en un troisième
     * vocabulaire (« REUSSIE »…) créerait une table de correspondance de plus à tenir à jour, pour
     * dire la même chose.</p>
     *
     * <p><b>Correction du 2026-09-04 (B4, ADR-056)</b> — ce Javadoc affirmait jusqu'ici que les frais
     * valaient toujours 0 (« le paiement est simulé, il n'y a aucune passerelle réelle ») et que ni
     * {@code structureSanitaireId} ni {@code facturePatientId} n'étaient portés (« le domaine ne les
     * porte pas »). C'était vrai pour les canaux simulés (mobile money, carte) ; ce n'est plus vrai
     * pour GeniusPay, qui EST une passerelle réelle. Les deux affirmations restent EXACTES pour tout
     * paiement dont {@link TransitionTerminaleEvenement#etablissementRef()} est {@code null} (aucun
     * émetteur ne l'a fourni) : la charge porte alors {@code fraisPasserelle: null} et
     * {@code etablissementRef: null}, jamais 0 ni une valeur inventée.</p>
     *
     * <p>{@code etablissementRef} : recopié tel quel — c'est à Laravel de le résoudre en
     * {@code structure_sanitaire_id} local, jamais à ce service de le deviner. {@code factureId} :
     * l'identifiant INTERNE au microservice (P5.2a) ; Laravel ne l'utilise pas pour rattacher une
     * facture patient (les deux domaines de facturation sont distincts), mais le porter permet de
     * retrouver la transaction source en cas de litige. {@code fraisPrestataire} reste à 0,
     * explicitement : ce canal n'a qu'un seul poste de frais réel ({@code fraisPasserelle}) — il n'y a
     * pas de second poste « prestataire » distinct à connaître ici, ce n'est donc pas une valeur par
     * défaut mais un fait du domaine.</p>
     *
     * <p>{@code paiementId} — AJOUTÉ EN COURS D'EXÉCUTION DE B4, absent du plan initial. Le champ
     * existait déjà (c'est le paramètre {@code agregatId} de l'outbox), mais n'était jusqu'ici jamais
     * mis DANS la charge JSON elle-même. Laravel en a besoin comme clé d'idempotence de la commission
     * : un {@code Paiement} ne peut atteindre un état terminal qu'UNE SEULE fois (la garde de
     * répétition de {@code setStatut} l'empêche), donc {@code paiementId} identifie sans ambiguïté LA
     * transition qui a déclenché cette notification — même après un rejeu du relais.</p>
     */
    private static Map<String, Object> charge(TransitionTerminaleEvenement e) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("paiementId", e.paiementId().toString());
        m.put("correlationId", e.correlationId());
        m.put("montant", e.montant());
        m.put("devise", e.devise());
        m.put("statut", e.statut().name());
        m.put("dateTransaction", e.survenuLe().toString());
        m.put("etablissementRef", e.etablissementRef());
        m.put("factureId", e.factureId() == null ? null : e.factureId().toString());
        m.put("fraisPasserelle", e.fraisPasserelle());
        m.put("fraisPrestataire", 0);
        // Discriminant du canal (ajouté en cours d'exécution, cf. Javadoc de l'événement) : Laravel
        // ne calcule une commission MaSanté QUE sur "geniuspay", jamais sur carte/mobile money —
        // aucune politique de commission platform-wide n'a été décidée pour ces canaux.
        m.put("canal", e.canal());
        return m;
    }
}
