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
     * <p><b>Les frais valent 0, explicitement, et ce n'est pas une valeur inventée</b> : le paiement
     * est simulé ({@code AdaptateurSimule}), il n'y a aucune passerelle réelle, donc aucun frais
     * réel — c'est le coût exact d'une passerelle qui n'existe pas encore. Les omettre laisserait le
     * lecteur croire à une information manquante ; les estimer produirait une facturation fausse.</p>
     *
     * <p>Ni {@code structureSanitaireId} ni {@code facturePatientId} : le domaine ne les porte pas.
     * Les deviner depuis {@code correlationId} ou {@code factureId} (qui désigne une facture INTERNE
     * au microservice) rattacherait des commissions à la mauvaise structure.</p>
     */
    private static Map<String, Object> charge(TransitionTerminaleEvenement e) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("correlationId", e.correlationId());
        m.put("montant", e.montant());
        m.put("devise", e.devise());
        m.put("statut", e.statut().name());
        m.put("dateTransaction", e.survenuLe().toString());
        m.put("fraisPasserelle", 0);
        m.put("fraisPrestataire", 0);
        return m;
    }
}
