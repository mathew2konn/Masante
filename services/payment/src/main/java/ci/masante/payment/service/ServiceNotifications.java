package ci.masante.payment.service;

import ci.masante.payment.domain.model.NotificationSortie;
import ci.masante.payment.domain.notification.EnvoiNotification;
import ci.masante.payment.domain.notification.MessageNotification;
import ci.masante.payment.domain.notification.ResultatEnvoi;
import ci.masante.payment.domain.notification.StatutNotification;
import ci.masante.payment.domain.notification.TypeNotification;
import ci.masante.payment.repository.NotificationSortieRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.context.annotation.Lazy;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Notifications sortantes (CDC_06 §5.4) via <b>Outbox Pattern</b> (CDC_03 §8) : {@link #emettre} écrit une
 * ligne dans la MÊME transaction que le changement métier (aucun message perdu ni publié avant commit) ; le
 * relais {@link #envoyerEnAttente} la livre ensuite via le port {@link EnvoiNotification} (SIMULÉ, FT5).
 *
 * <p>Frontière : le contenu est une donnée ; aucun métier ici. Le relais est idempotent (verrou pessimiste
 * + garde d'état) : une ligne déjà ENVOYEE/ECHOUEE n'est jamais re-livrée.</p>
 */
@Service
public class ServiceNotifications {

    private final NotificationSortieRepository outbox;
    private final EnvoiNotification envoi;
    private final ObjectMapper json;
    private final ServiceNotifications self;

    public ServiceNotifications(NotificationSortieRepository outbox,
                                EnvoiNotification envoi,
                                ObjectMapper json,
                                @Lazy ServiceNotifications self) {
        this.outbox = outbox;
        this.envoi = envoi;
        this.json = json;
        this.self = self;
    }

    /**
     * Enfile une notification dans l'outbox. À appeler DANS la transaction du changement métier (préavis,
     * échec de prélèvement) : la ligne est committée avec lui, jamais avant.
     */
    public void emettre(TypeNotification type, String agregatType, UUID agregatId, String destinataireRef,
                        Map<String, Object> charge) {
        outbox.save(new NotificationSortie(type, agregatType, agregatId, destinataireRef, "AUTO", serialiser(charge)));
    }

    /** Relaie les notifications en attente (chacune dans sa propre transaction). Retourne le nombre livré. */
    public int envoyerEnAttente() {
        int livrees = 0;
        for (NotificationSortie ref : outbox.findTop200ByStatutOrderByCreeLeAsc(StatutNotification.EN_ATTENTE)) {
            if (self.livrerUne(ref.getId())) {
                livrees++;
            }
        }
        return livrees;
    }

    @Transactional
    public boolean livrerUne(UUID id) {
        NotificationSortie notif = outbox.verrouiller(id).orElse(null);
        if (notif == null || !notif.estEnAttente()) {
            return false; // déjà livrée/échouée ou disparue → idempotent
        }
        ResultatEnvoi r = envoi.envoyer(new MessageNotification(
                notif.getType(), notif.getDestinataireRef(), notif.getCanalSouhaite(), notif.getChargeUtile()));
        if (r.reussi()) {
            notif.marquerEnvoyee(r.canal(), Instant.now());
        } else {
            notif.marquerEchouee(r.detail(), Instant.now());
        }
        outbox.save(notif);
        return r.reussi();
    }

    @Transactional(readOnly = true)
    public List<NotificationSortie> pourDestinataire(String destinataireRef) {
        return outbox.findByDestinataireRefOrderByCreeLeDesc(destinataireRef);
    }

    private String serialiser(Map<String, Object> charge) {
        try {
            return json.writeValueAsString(charge);
        } catch (com.fasterxml.jackson.core.JsonProcessingException e) {
            throw new IllegalStateException("Sérialisation de la charge de notification impossible", e);
        }
    }
}
