package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.RegistrePasserelles;
import ci.masante.payment.domain.gateway.RequetePaiement;
import ci.masante.payment.domain.gateway.ResultatPaiement;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.GeniusPayException;
import ci.masante.payment.domain.gateway.geniuspay.GeniusPayInjoignableException;
import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.StatutGeniusPay;
import ci.masante.payment.domain.model.TransitionPaiement;
import ci.masante.payment.domain.statemachine.MachineEtatsPaiement;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Lazy;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.security.SecureRandom;
import java.time.Instant;
import java.util.EnumSet;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;
import java.util.UUID;

/**
 * Initiation d'un paiement GeniusPay (§7.5).
 *
 * <h2>LA RÈGLE DU NON-REJEU — à lire deux fois avant de toucher à cette classe</h2>
 *
 * <p>GeniusPay <b>n'offre aucune clé d'idempotence</b> sur {@code POST /payments} : la vérification
 * en bac à sable l'a confirmé, aucun en-tête de requête ou de corrélation n'existe. Et sa recherche
 * ne porte pas sur {@code metadata.order_id}. Deux conséquences non négociables :</p>
 *
 * <p><b>a) Un {@code POST /payments} ne se rejoue jamais.</b> Ni {@code @Retryable}, ni boucle, ni
 * rejeu manuel, ni « une seconde tentative pour la robustesse ». Un délai dépassé ou une coupure
 * réseau laisse la transaction en {@link StatutGeniusPay#INITIEE_INCERTAINE}. Rejouer, c'est risquer
 * deux transactions chez le prestataire pour une seule facture — donc, potentiellement,
 * <b>deux débits sur un patient</b>. C'est le genre de règle qu'un développeur pressé casse en
 * ajoutant trois caractères, en croyant améliorer la robustesse.</p>
 *
 * <p><b>b) La levée d'incertitude passe par deux chemins, dans cet ordre.</b></p>
 * <ul>
 *   <li><i>Nominal — le webhook.</i> Si la transaction a bien été créée, l'événement arrivera avec
 *       {@code metadata.order_id} égal à notre référence interne. C'est pour ce seul cas que
 *       {@code order_id} est envoyé sur <b>chaque</b> appel, sans exception : sans lui, une
 *       transaction incertaine ne serait jamais rattachée.</li>
 *   <li><i>Secours — le balayage.</i> Lister les paiements du jour et interroger chaque candidat
 *       jusqu'à retrouver notre {@code order_id}. Coûteux, donc plafonné et exécuté hors du fil de la
 *       requête.</li>
 * </ul>
 *
 * <p>Si les deux chemins échouent au-delà de l'échéance d'abandon, la transaction passe à
 * {@link StatutGeniusPay#EXPIREE} et le partenaire est notifié pour remise à sa charge.
 * <b>Aucune facture n'est jamais soldée sur une hypothèse.</b></p>
 */
@Service
public class ServiceGeniusPay {

    private static final Logger log = LoggerFactory.getLogger(ServiceGeniusPay.class);

    /**
     * États pour lesquels une nouvelle initiation n'a pas lieu d'être : soit le paiement a abouti,
     * soit un checkout est en cours, soit nous ne savons pas — et dans ce dernier cas, en ouvrir un
     * second serait précisément le double débit qu'on refuse.
     */
    private static final Set<StatutGeniusPay> ETATS_BLOQUANT_UNE_NOUVELLE_INITIATION = EnumSet.of(
            StatutGeniusPay.REUSSIE, StatutGeniusPay.INITIEE, StatutGeniusPay.INITIEE_INCERTAINE,
            StatutGeniusPay.EN_ATTENTE, StatutGeniusPay.EN_COURS);

    private final PaiementRepository paiements;
    private final TransitionPaiementRepository transitions;
    private final GeniusPayTransactionRepository geniusPayTransactions;
    private final RegistrePasserelles passerelles;
    private final ServiceIdempotence idempotence;
    private final ServiceAudit audit;
    private final ProprietesGeniusPay proprietes;
    private final long plancherEnLigne;
    private final ServiceGeniusPay self;

    public ServiceGeniusPay(PaiementRepository paiements,
                            TransitionPaiementRepository transitions,
                            GeniusPayTransactionRepository geniusPayTransactions,
                            RegistrePasserelles passerelles,
                            ServiceIdempotence idempotence,
                            ServiceAudit audit,
                            ProprietesGeniusPay proprietes,
                            @Value("${masante.payment.plancher-en-ligne-fcfa}") long plancherEnLigne,
                            @Lazy ServiceGeniusPay self) {
        this.paiements = paiements;
        this.transitions = transitions;
        this.geniusPayTransactions = geniusPayTransactions;
        this.passerelles = passerelles;
        this.idempotence = idempotence;
        this.audit = audit;
        this.proprietes = proprietes;
        this.plancherEnLigne = plancherEnLigne;
        // Auto-référence par proxy : un appel this.executer(...) court-circuiterait la transaction.
        this.self = self;
    }

    /**
     * Ouvre un checkout pour une facture.
     *
     * @param cleIdempotence clé fournie par l'appelant ; deux appels concurrents avec la même clé ne
     *                       produisent qu'un seul paiement.
     */
    public ResultatCheckout initierPourFacture(DemandeCheckout demande, String cleIdempotence) {
        // 1. Plancher métier (R17) : sous ce montant, le paiement sur place reste la voie normale.
        //    Contrôlé AVANT tout, y compris avant l'idempotence : un montant refusé ne consomme pas
        //    de clé et n'écrit rien.
        if (demande.montant() < plancherEnLigne) {
            throw new PaiementEnLigneIndisponibleException(
                    "Le paiement en ligne n'est pas disponible sous " + plancherEnLigne + " FCFA.");
        }
        // 2. Minimum imposé par le prestataire. Séparé du précédent à dessein : ce n'est pas la même
        //    règle, elle ne vient pas de nous, et le message doit dire laquelle a joué.
        if (demande.montant() < proprietes.getMontantMinimum()) {
            throw new PaiementEnLigneIndisponibleException(
                    "Le prestataire refuse les montants inférieurs à " + proprietes.getMontantMinimum() + " FCFA.");
        }

        var existant = paiements.findByIdempotencyKey(cleIdempotence);
        if (existant.isPresent()) {
            return depuisPaiement(existant.get(), true);
        }
        if (!idempotence.acquerir(cleIdempotence)) {
            return paiements.findByIdempotencyKey(cleIdempotence)
                    .map(p -> depuisPaiement(p, true))
                    .orElseThrow(() -> new ConflitIdempotenceException(cleIdempotence));
        }
        try {
            return self.executer(demande, cleIdempotence);
        } finally {
            idempotence.liberer(cleIdempotence);
        }
    }

    @Transactional
    public ResultatCheckout executer(DemandeCheckout demande, String cleIdempotence) {
        var deja = paiements.findByIdempotencyKey(cleIdempotence);
        if (deja.isPresent()) {
            return depuisPaiement(deja.get(), true);
        }

        // 3. Une facture déjà couverte par un checkout vivant ne s'en voit pas ouvrir un second.
        //    Le lien encore valable est RÉUTILISÉ : en rouvrir un ferait cohabiter deux liens payables
        //    pour la même facture, et le patient n'a aucun moyen de savoir lequel est le bon.
        Optional<GeniusPayTransaction> enCours = geniusPayTransactions.findByFactureId(demande.factureId()).stream()
                .filter(t -> ETATS_BLOQUANT_UNE_NOUVELLE_INITIATION.contains(t.getStatutGeniusPay()))
                .findFirst();
        if (enCours.isPresent()) {
            GeniusPayTransaction t = enCours.get();
            log.info("Checkout GeniusPay déjà ouvert pour la facture {} (ref={}) — réutilisation.",
                    demande.factureId(), t.getReferenceInterne());
            return new ResultatCheckout(t, paiements.findById(t.getPaiementId()).orElseThrow(), true);
        }

        // 4/5/6. Le paiement partagé et la transaction satellite sont persistés AVANT l'appel réseau.
        //        C'est ce qui rend l'incertitude représentable : si l'appel se perd, la trace existe.
        Paiement paiement = paiements.save(new Paiement(
                cleIdempotence, demande.correlationId(), demande.montant(),
                demande.devise() == null ? "XOF" : demande.devise(), AdaptateurGeniusPay.CANAL,
                demande.objet(), null, demande.etablissementRef(), demande.patientRef()));

        String referenceInterne = referenceInterne(demande.etablissementRef());
        GeniusPayTransaction transaction = geniusPayTransactions.save(
                new GeniusPayTransaction(paiement.getId(), referenceInterne, demande.factureId()));

        auditer("GeniusPayCheckoutRequested", paiement,
                Map.of("referenceInterne", referenceInterne, "factureId", demande.factureId().toString()));

        appliquer(paiement, PaiementStatut.PENDING, "Ouverture d'un checkout GeniusPay");

        // 7. UN SEUL APPEL. Aucun rejeu, quelle que soit l'issue.
        ResultatPaiement resultat;
        try {
            resultat = passerelles.pour(AdaptateurGeniusPay.CANAL).payer(new RequetePaiement(
                    referenceInterne, paiement.getMontant(), paiement.getDevise(),
                    AdaptateurGeniusPay.CANAL, paiement.getObjet(), null, paiement.getCorrelationId(),
                    demande.etablissementRef(), demande.factureId()));
        } catch (GeniusPayInjoignableException e) {
            // NOUS NE SAVONS PAS si la transaction existe chez le prestataire. On l'écrit tel quel.
            transaction.setStatutGeniusPay(StatutGeniusPay.INITIEE_INCERTAINE);
            geniusPayTransactions.save(transaction);
            auditer("GeniusPayIncertain", paiement, Map.of("referenceInterne", referenceInterne));
            log.warn("Initiation GeniusPay incertaine pour {} — aucun second appel ne sera fait.",
                    referenceInterne);
            return new ResultatCheckout(transaction, paiement, false);
        } catch (GeniusPayException e) {
            // Le prestataire a REFUSÉ : c'est une réponse, pas une incertitude. Aucun de ses codes
            // d'erreur n'est ré-essayable sur une initiation (§4.3).
            transaction.setStatutGeniusPay(StatutGeniusPay.ECHOUEE);
            transaction.setCodeErreur(e.getCode());
            transaction.setFinaliseeLe(Instant.now());
            geniusPayTransactions.save(transaction);
            appliquer(paiement, PaiementStatut.FAILED, "Initiation refusée par le prestataire");
            auditer("GeniusPayRefused", paiement, Map.of("code", e.getCode()));
            return new ResultatCheckout(transaction, paiement, false);
        }

        transaction.setReferencePasserelle(resultat.referenceOperateur());
        transaction.setStatutGeniusPay(StatutGeniusPay.EN_ATTENTE);
        if (resultat.checkout() != null) {
            transaction.setCheckoutUrl(resultat.checkout().checkoutUrl());
            transaction.setExpireLe(resultat.checkout().expireLe());
            transaction.setFraisPasserelle(resultat.checkout().frais());
            transaction.setMontantNet(resultat.checkout().net());
            transaction.setCanal(resultat.checkout().canalReel());
        }
        geniusPayTransactions.save(transaction);
        paiement.setProviderRef(resultat.referenceOperateur());
        paiements.save(paiement);

        auditer("GeniusPayCheckoutOpened", paiement, Map.of(
                "referenceInterne", referenceInterne,
                "referencePasserelle", nz(resultat.referenceOperateur())));
        return new ResultatCheckout(transaction, paiement, false);
    }

    @Transactional(readOnly = true)
    public ResultatCheckout parReferenceInterne(String referenceInterne) {
        GeniusPayTransaction t = geniusPayTransactions.findByReferenceInterne(referenceInterne)
                .orElseThrow(() -> new PaiementIntrouvableException(referenceInterne));
        Paiement p = paiements.findById(t.getPaiementId())
                .orElseThrow(() -> new PaiementIntrouvableException(referenceInterne));
        return new ResultatCheckout(t, p, false);
    }

    private ResultatCheckout depuisPaiement(Paiement paiement, boolean rejoue) {
        GeniusPayTransaction t = geniusPayTransactions.findByPaiementId(paiement.getId())
                .orElseThrow(() -> new PaiementIntrouvableException(paiement.getId().toString()));
        return new ResultatCheckout(t, paiement, rejoue);
    }

    private void appliquer(Paiement paiement, PaiementStatut vers, String raison) {
        PaiementStatut de = paiement.getStatut();
        MachineEtatsPaiement.verifier(de, vers);
        transitions.save(new TransitionPaiement(paiement.getId(), de, vers, raison));
        paiement.setStatut(vers);
        paiements.save(paiement);
    }

    private void auditer(String evenement, Paiement paiement, Map<String, Object> extra) {
        Map<String, Object> charge = new HashMap<>(extra);
        charge.put("statut", paiement.getStatut().name());
        audit.enregistrer(evenement, "payment", paiement.getId().toString(), charge);
    }

    /**
     * {@code MS-{établissement}-{ULID}} — unique à vie, jamais réutilisée.
     *
     * <p>Un ULID plutôt qu'un UUID parce qu'il est <b>triable dans le temps</b> : les quarante-huit
     * premiers bits sont l'horodatage en millisecondes. Dans un journal ou une liste du prestataire,
     * l'ordre lexicographique est l'ordre chronologique — ce qui compte le jour où il faut retrouver
     * « les transactions de ce matin ». Généré en JDK pur (Crockford base32), aucune dépendance.</p>
     */
    static String referenceInterne(String etablissementRef) {
        return "MS-" + assainir(etablissementRef) + "-" + ulid();
    }

    /** L'établissement peut porter des caractères inadaptés à une référence transmise à un tiers. */
    private static String assainir(String valeur) {
        if (valeur == null || valeur.isBlank()) {
            return "NA";
        }
        String propre = valeur.replaceAll("[^A-Za-z0-9]", "").toUpperCase(java.util.Locale.ROOT);
        return propre.isEmpty() ? "NA" : propre.substring(0, Math.min(12, propre.length()));
    }

    private static final char[] CROCKFORD = "0123456789ABCDEFGHJKMNPQRSTVWXYZ".toCharArray();
    private static final SecureRandom ALEA = new SecureRandom();

    private static String ulid() {
        long horodatage = System.currentTimeMillis();
        char[] sortie = new char[26];
        for (int i = 9; i >= 0; i--) {
            sortie[i] = CROCKFORD[(int) (horodatage & 0x1F)];
            horodatage >>>= 5;
        }
        byte[] hasard = new byte[16];
        ALEA.nextBytes(hasard);
        for (int i = 10; i < 26; i++) {
            sortie[i] = CROCKFORD[hasard[i - 10] & 0x1F];
        }
        return new String(sortie);
    }

    private static String nz(String v) {
        return v == null ? "" : v;
    }

    /** Demande d'ouverture de checkout. Aucun champ médical : ni acte, ni code, ni spécialité. */
    public record DemandeCheckout(
            UUID factureId,
            long montant,
            String devise,
            String etablissementRef,
            String patientRef,
            String correlationId,
            ci.masante.payment.domain.model.ObjetPaiement objet
    ) {
    }

    /** Résultat rendu à l'appelant. {@code rejoue} distingue une création d'un renvoi à l'identique. */
    public record ResultatCheckout(GeniusPayTransaction transaction, Paiement paiement, boolean rejoue) {

        public List<String> avertissements() {
            return transaction.getStatutGeniusPay() == StatutGeniusPay.INITIEE_INCERTAINE
                    ? List.of("L'initiation n'a pas pu être confirmée par le prestataire. "
                              + "Aucun second appel ne sera fait ; la situation sera levée automatiquement.")
                    : List.of();
        }
    }
}
