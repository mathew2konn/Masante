package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.model.CarteEvenementWebhook;
import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.StatutGeniusPay;
import ci.masante.payment.repository.CarteEvenementWebhookRepository;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

import java.time.Duration;
import java.time.Instant;
import java.util.EnumSet;
import java.util.List;

/**
 * Traitement hors requête et réconciliation (§8.3, §8.5).
 *
 * <p><b>Aucun {@code @Async} n'a été ajouté</b>, et ce n'est pas un contournement. Le service ne
 * dispose ni d'exécuteur asynchrone ni de file de messages — la Phase 0 l'a établi. Ce qu'il a, en
 * revanche, c'est un motif déjà éprouvé pour « faire quelque chose hors du fil de la requête » :
 * l'Outbox de notification (P5.4c), où l'on <b>persiste d'abord</b> et où un relais planifié
 * applique ensuite. On l'applique ici sans en inventer un second : un événement webhook enregistré en
 * base est une file d'attente durable, qui survit à un redémarrage — ce qu'une file en mémoire ne
 * fait pas.</p>
 */
@Component
@ConditionalOnProperty(value = "masante.payment.geniuspay.planification.enabled", havingValue = "true",
        matchIfMissing = true)
public class PlanificateurGeniusPay {

    private static final Logger log = LoggerFactory.getLogger(PlanificateurGeniusPay.class);

    private final CarteEvenementWebhookRepository evenements;
    private final GeniusPayTransactionRepository transactions;
    private final ServiceWebhookGeniusPay webhooks;
    private final ServiceReconciliationGeniusPay reconciliation;
    private final ProprietesGeniusPay proprietes;

    public PlanificateurGeniusPay(CarteEvenementWebhookRepository evenements,
                                  GeniusPayTransactionRepository transactions,
                                  ServiceWebhookGeniusPay webhooks,
                                  ServiceReconciliationGeniusPay reconciliation,
                                  ProprietesGeniusPay proprietes) {
        this.evenements = evenements;
        this.transactions = transactions;
        this.webhooks = webhooks;
        this.reconciliation = reconciliation;
        this.proprietes = proprietes;
    }

    /**
     * Applique les événements reçus. Chaque événement est traité <b>dans sa propre transaction</b> :
     * l'échec de l'un ne doit pas emporter les autres — un montant divergent sur une transaction n'a
     * aucune raison d'empêcher le succès légitime de la suivante.
     */
    @Scheduled(fixedDelayString = "${masante.payment.geniuspay.planification.traitement-evenements-ms:5000}")
    public void traiterEvenementsRecus() {
        List<CarteEvenementWebhook> aTraiter = evenements
                .findTop50ByPspAndStatutTraitementOrderByRecuLeAsc(
                        AdaptateurGeniusPay.PSP, ServiceWebhookGeniusPay.RECU);
        for (CarteEvenementWebhook evenement : aTraiter) {
            try {
                webhooks.appliquer(evenement.getId());
            } catch (RuntimeException e) {
                // UN ÉVÉNEMENT EN ÉCHEC NE SE REJOUE PAS INDÉFINIMENT. Sans cette ligne, un événement
                // que rien ne pourra jamais appliquer — une référence incohérente, une contrainte
                // violée — repasse toutes les cinq secondes et noie le journal, jusqu'à rendre
                // invisibles les incidents qui, eux, méritent d'être vus. Le G2 l'a montré en direct.
                // On le marque en ERREUR : il devient un incident CONSULTABLE au lieu d'un bruit.
                log.error("Échec du traitement de l'événement GeniusPay {} : {} — classé en ERREUR.",
                        evenement.getEvenementId(), e.getClass().getSimpleName());
                webhooks.marquerEnErreur(evenement.getId(), e.getClass().getSimpleName());
            }
        }
    }

    /**
     * Réconciliation (§8.5). Trois populations, trois traitements — et un seul automate, celui de
     * {@code MachineEtatsGeniusPay}, partagé avec le traitement webhook. Deux implémentations
     * divergentes du même automate seraient une bombe à retardement portant sur de l'argent.
     */
    @Scheduled(fixedDelayString = "${masante.payment.geniuspay.planification.reconciliation-ms:600000}")
    public void reconcilier() {
        Instant maintenant = Instant.now();
        Instant limiteAncienneté = maintenant.minus(Duration.ofHours(proprietes.getAbandonApresHeures()));

        // 1. Non terminales, référence connue, de plus de 5 minutes : on demande leur statut.
        List<GeniusPayTransaction> aConsulter = transactions
                .findByStatutGeniusPayInAndReferencePasserelleIsNotNullAndInitieeLeBetween(
                        EnumSet.of(StatutGeniusPay.EN_ATTENTE, StatutGeniusPay.EN_COURS,
                                StatutGeniusPay.INITIEE, StatutGeniusPay.INITIEE_INCERTAINE),
                        limiteAncienneté, maintenant.minus(Duration.ofMinutes(5)));
        for (GeniusPayTransaction t : aConsulter) {
            sansPropager(() -> reconciliation.rattraper(t.getId()), t);
        }

        // 2. Incertaines sans référence, au-delà du délai : balayage §7.4.b.
        List<GeniusPayTransaction> aBalayer = transactions
                .findByStatutGeniusPayAndReferencePasserelleIsNullAndInitieeLeBefore(
                        StatutGeniusPay.INITIEE_INCERTAINE,
                        maintenant.minus(Duration.ofMinutes(proprietes.getLeveeIncertitudeMinutes())));
        for (GeniusPayTransaction t : aBalayer) {
            if (t.getInitieeLe().isAfter(limiteAncienneté)) {
                sansPropager(() -> reconciliation.leverIncertitude(t.getId()), t);
            }
        }

        // 3. Au-delà de l'échéance d'abandon : la transaction est déclarée échue et le partenaire
        //    notifié pour remise à sa charge. AUCUNE facture n'est soldée sur une hypothèse — et
        //    c'est bien dans ce sens-là que penche le doute.
        reconciliation.abandonnerLesEchues(limiteAncienneté);
    }

    private void sansPropager(Runnable action, GeniusPayTransaction t) {
        try {
            action.run();
        } catch (RuntimeException e) {
            log.warn("Réconciliation GeniusPay impossible pour {} : {}",
                    t.getReferenceInterne(), e.getClass().getSimpleName());
        }
    }
}
