package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.RegistrePasserelles;
import ci.masante.payment.domain.gateway.ResultatPaiement;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.ClientGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.MappeurStatutGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.ReponsesGeniusPay;
import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.IdentifiantMarchand;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.StatutGeniusPay;
import ci.masante.payment.domain.model.TransitionPaiement;
import ci.masante.payment.domain.statemachine.MachineEtatsGeniusPay;
import ci.masante.payment.domain.statemachine.MachineEtatsPaiement;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import ci.masante.payment.repository.IdentifiantMarchandRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.EnumSet;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

/**
 * Réconciliation et levée d'incertitude (§8.5, §7.4.b).
 *
 * <p>Elle rattrape ce que le webhook n'a pas apporté — une coupure du tunnel, un renvoi perdu, une
 * initiation dont on ignore l'issue. <b>Elle applique le même automate</b> que le traitement webhook
 * ({@link MachineEtatsGeniusPay}) : c'est ce partage qui garantit qu'une transaction rattrapée finit
 * dans le même état qu'une transaction notifiée.</p>
 */
@Service
public class ServiceReconciliationGeniusPay {

    private static final Logger log = LoggerFactory.getLogger(ServiceReconciliationGeniusPay.class);

    private final GeniusPayTransactionRepository transactions;
    private final PaiementRepository paiements;
    private final TransitionPaiementRepository transitionsPaiement;
    private final IdentifiantMarchandRepository marchands;
    private final GestionnaireSecretsMarchand secrets;
    private final ClientGeniusPay client;
    private final RegistrePasserelles passerelles;
    private final ServiceAudit audit;
    private final ServiceFacturation facturation;
    private final ProprietesGeniusPay proprietes;

    public ServiceReconciliationGeniusPay(GeniusPayTransactionRepository transactions,
                                          PaiementRepository paiements,
                                          TransitionPaiementRepository transitionsPaiement,
                                          IdentifiantMarchandRepository marchands,
                                          GestionnaireSecretsMarchand secrets,
                                          ClientGeniusPay client,
                                          RegistrePasserelles passerelles,
                                          ServiceAudit audit,
                                          ServiceFacturation facturation,
                                          ProprietesGeniusPay proprietes) {
        this.transactions = transactions;
        this.paiements = paiements;
        this.transitionsPaiement = transitionsPaiement;
        this.marchands = marchands;
        this.secrets = secrets;
        this.client = client;
        this.passerelles = passerelles;
        this.audit = audit;
        this.facturation = facturation;
        this.proprietes = proprietes;
    }

    /** Transaction dont on connaît la référence : on demande simplement son statut au prestataire. */
    @Transactional
    public void rattraper(UUID transactionId) {
        GeniusPayTransaction transaction = transactions.verrouiller(transactionId).orElse(null);
        if (transaction == null || transaction.getStatutGeniusPay().estTerminal()
                || transaction.getReferencePasserelle() == null) {
            return;
        }
        ResultatPaiement resultat = passerelles.pour(AdaptateurGeniusPay.CANAL)
                .statut(transaction.getReferencePasserelle());
        transaction.setDerniereVerificationLe(Instant.now());

        StatutGeniusPay cible = depuisStatutPartage(resultat.statut(), transaction.getStatutGeniusPay());
        if (!MachineEtatsGeniusPay.estAutorisee(transaction.getStatutGeniusPay(), cible)) {
            transactions.save(transaction);
            return;
        }
        if (resultat.checkout() != null) {
            if (resultat.checkout().frais() != null) {
                transaction.setFraisPasserelle(resultat.checkout().frais());
            }
            if (resultat.checkout().net() != null) {
                transaction.setMontantNet(resultat.checkout().net());
            }
            if (resultat.checkout().canalReel() != null) {
                transaction.setCanal(resultat.checkout().canalReel());
            }
        }
        poser(transaction, cible, "Réconciliation GeniusPay");
    }

    /**
     * Levée d'incertitude par balayage (§7.4.b) : la transaction n'a pas de référence prestataire, on
     * ne peut donc pas l'interroger directement. On liste les paiements de la fenêtre et on
     * interroge chaque candidat jusqu'à retrouver notre {@code metadata.order_id}.
     *
     * <p><b>Coûteux, donc plafonné</b> : le nombre de consultations unitaires est une donnée de
     * configuration. Sans plafond, une journée chargée transformerait ce rattrapage en avalanche
     * d'appels sortants.</p>
     */
    @Transactional
    public void leverIncertitude(UUID transactionId) {
        GeniusPayTransaction transaction = transactions.verrouiller(transactionId).orElse(null);
        if (transaction == null || transaction.getStatutGeniusPay() != StatutGeniusPay.INITIEE_INCERTAINE
                || transaction.getReferencePasserelle() != null) {
            return;
        }
        Paiement paiement = paiements.findById(transaction.getPaiementId()).orElse(null);
        if (paiement == null) {
            return;
        }
        Optional<IdentifiantMarchand> marchand = marchands
                .findByEtablissementRefAndPspAndActifIsTrue(paiement.getEtablissementRef(),
                        AdaptateurGeniusPay.PSP);
        if (marchand.isEmpty()) {
            return;
        }
        String cle = marchand.get().getClePublique();
        String secret = secrets.cleSecrete(marchand.get());

        String jour = transaction.getInitieeLe().atZone(java.time.ZoneOffset.UTC).toLocalDate().toString();
        List<ReponsesGeniusPay.Paiement> candidats =
                client.lister(cle, secret, jour, null, 100);

        int consultations = 0;
        for (ReponsesGeniusPay.Paiement candidat : candidats) {
            if (consultations >= proprietes.getBalayageMaxConsultations()) {
                log.warn("Balayage GeniusPay plafonné à {} consultations — reprise au prochain passage.",
                        proprietes.getBalayageMaxConsultations());
                break;
            }
            // La réponse de LISTE ne contient pas `metadata` : il faut interroger chaque référence
            // individuellement. Contrainte du prestataire, pas un choix de conception.
            consultations++;
            Optional<ReponsesGeniusPay.Paiement> detail =
                    client.consulter(cle, secret, candidat.reference());
            if (detail.isEmpty()) {
                continue;
            }
            Object orderId = detail.get().metadata() == null ? null : detail.get().metadata().get("order_id");
            if (!transaction.getReferenceInterne().equals(orderId)) {
                continue;
            }
            // Trouvée : l'incertitude se lève.
            transaction.setReferencePasserelle(detail.get().reference());
            transaction.setDerniereVerificationLe(Instant.now());
            StatutGeniusPay cible = MappeurStatutGeniusPay.depuisStatutApi(detail.get().status())
                    .orElse(StatutGeniusPay.EN_ATTENTE);
            if (MachineEtatsGeniusPay.estAutorisee(transaction.getStatutGeniusPay(), cible)) {
                poser(transaction, cible, "Levée d'incertitude par balayage");
            } else {
                transactions.save(transaction);
            }
            audit.enregistrer("GeniusPayUncertaintyResolved", "payment", paiement.getId().toString(),
                    Map.of("referenceInterne", transaction.getReferenceInterne()));
            return;
        }
        transaction.setDerniereVerificationLe(Instant.now());
        transactions.save(transaction);
    }

    /**
     * Au-delà de l'échéance, une transaction jamais confirmée est déclarée échue.
     *
     * <p>C'est le point où le doute est tranché, et il l'est <b>dans le sens sûr</b> : la facture
     * n'est pas soldée, elle retourne à la charge du partenaire. Trancher dans l'autre sens
     * marquerait payée une facture dont rien ne prouve qu'elle l'a été.</p>
     */
    @Transactional
    public void abandonnerLesEchues(Instant limite) {
        List<GeniusPayTransaction> echues = transactions
                .findByStatutGeniusPayInAndReferencePasserelleIsNotNullAndInitieeLeBetween(
                        EnumSet.of(StatutGeniusPay.INITIEE, StatutGeniusPay.INITIEE_INCERTAINE,
                                StatutGeniusPay.EN_ATTENTE, StatutGeniusPay.EN_COURS),
                        Instant.EPOCH, limite);
        List<GeniusPayTransaction> sansReference = transactions
                .findByStatutGeniusPayAndReferencePasserelleIsNullAndInitieeLeBefore(
                        StatutGeniusPay.INITIEE_INCERTAINE, limite);

        for (GeniusPayTransaction t : concat(echues, sansReference)) {
            GeniusPayTransaction verrouillee = transactions.verrouiller(t.getId()).orElse(null);
            if (verrouillee == null || verrouillee.getStatutGeniusPay().estTerminal()) {
                continue;
            }
            if (!MachineEtatsGeniusPay.estAutorisee(verrouillee.getStatutGeniusPay(), StatutGeniusPay.EXPIREE)) {
                continue;
            }
            poser(verrouillee, StatutGeniusPay.EXPIREE, "Échéance dépassée sans confirmation");
            log.warn("Transaction GeniusPay {} déclarée échue : aucune confirmation reçue dans le délai.",
                    verrouillee.getReferenceInterne());
        }
    }

    /**
     * Pose le sous-état, puis projette sur la machine partagée. Le {@code setStatut} du paiement est le
     * point d'accroche unique du canal interne (lot 6) : la notification au partenaire part de là, sans
     * une ligne de code supplémentaire ici.
     */
    private void poser(GeniusPayTransaction transaction, StatutGeniusPay cible, String raison) {
        transaction.setStatutGeniusPay(cible);
        if (cible.estTerminal()) {
            transaction.setFinaliseeLe(Instant.now());
        }
        transactions.save(transaction);

        Paiement paiement = paiements.findById(transaction.getPaiementId()).orElse(null);
        if (paiement == null) {
            return;
        }
        PaiementStatut vise = cible.versStatutPartage();
        if (paiement.getStatut() != vise && MachineEtatsPaiement.estAutorisee(paiement.getStatut(), vise)) {
            transitionsPaiement.save(new TransitionPaiement(paiement.getId(), paiement.getStatut(), vise, raison));
            if (vise == PaiementStatut.SUCCESS) {
                paiement.setConfirmedAt(Instant.now());
                paiement.setFactureId(transaction.getFactureId());
                // Même règlement que par le webhook : une transaction rattrapée par la réconciliation
                // doit finir dans le MÊME état qu'une transaction notifiée, facture comprise. Sans
                // cela, la voie de rattrapage laisserait des factures impayées qui ont pourtant été
                // réglées — l'incohérence la plus difficile à retrouver ensuite.
                if (transaction.getFactureId() != null) {
                    facturation.enregistrerReglement(transaction.getFactureId(), paiement.getMontant());
                }
            }
            paiement.setStatut(vise);
            paiements.save(paiement);
        }
    }

    private static StatutGeniusPay depuisStatutPartage(PaiementStatut partage, StatutGeniusPay actuel) {
        return switch (partage) {
            case SUCCESS -> StatutGeniusPay.REUSSIE;
            case FAILED -> StatutGeniusPay.ECHOUEE;
            case CANCELLED -> StatutGeniusPay.ANNULEE;
            case REFUNDED -> StatutGeniusPay.REMBOURSEE;
            case PENDING -> StatutGeniusPay.EN_ATTENTE;
            case PROCESSING -> StatutGeniusPay.EN_COURS;
            // INITIATED ne dit rien de neuf : la transaction reste où elle est plutôt que de reculer.
            case INITIATED -> actuel;
        };
    }

    private static List<GeniusPayTransaction> concat(List<GeniusPayTransaction> a,
                                                     List<GeniusPayTransaction> b) {
        List<GeniusPayTransaction> tout = new java.util.ArrayList<>(a);
        tout.addAll(b);
        return tout;
    }
}
