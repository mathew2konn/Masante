package ci.masante.payment.service;

import ci.masante.payment.domain.carte.DemandeCarte;
import ci.masante.payment.domain.carte.Devise;
import ci.masante.payment.domain.carte.EvenementCarte;
import ci.masante.payment.domain.carte.EvenementWebhook;
import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.MachineEtatsCarte;
import ci.masante.payment.domain.carte.MappingStatutCarte;
import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.carte.PasserelleCarte;
import ci.masante.payment.domain.carte.RegistrePasserellesCarte;
import ci.masante.payment.domain.carte.ResultatCapture;
import ci.masante.payment.domain.carte.ResultatDebitRecurrent;
import ci.masante.payment.domain.carte.ResultatInitiation;
import ci.masante.payment.domain.carte.StatutCarte;
import ci.masante.payment.domain.carte.StatutPasserelle;
import ci.masante.payment.domain.gateway.ResultatRemboursement;
import ci.masante.payment.domain.model.Carte;
import ci.masante.payment.domain.model.CarteEvenementWebhook;
import ci.masante.payment.domain.model.CarteRemboursement;
import ci.masante.payment.domain.model.CarteTransaction;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.TransitionPaiement;
import ci.masante.payment.domain.statemachine.MachineEtatsPaiement;
import ci.masante.payment.repository.CarteEvenementWebhookRepository;
import ci.masante.payment.repository.CarteRemboursementRepository;
import ci.masante.payment.repository.CarteRepository;
import ci.masante.payment.repository.CarteTransactionRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.context.annotation.Lazy;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Duration;
import java.time.Instant;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

import static ci.masante.payment.domain.carte.EvenementCarte.AUTHENTIFICATION_REUSSIE;
import static ci.masante.payment.domain.carte.EvenementCarte.AUTORISATION_OK;
import static ci.masante.payment.domain.carte.EvenementCarte.CAPTURE_OK;
import static ci.masante.payment.domain.carte.EvenementCarte.EXPIRATION;
import static ci.masante.payment.domain.carte.EvenementCarte.INTERACTION_CLIENT_REQUISE;
import static ci.masante.payment.domain.carte.EvenementCarte.REFUS;
import static ci.masante.payment.domain.carte.EvenementCarte.REMBOURSEMENT_PARTIEL;
import static ci.masante.payment.domain.carte.EvenementCarte.REMBOURSEMENT_TOTAL;

/**
 * Orchestration du cycle de vie d'un paiement CARTE (CDC_06 §5, ADR-015). PAIEMENT SIMULÉ (FT5).
 *
 * <p><b>Frontière (interdit #1)</b> : le sous-état {@link StatutCarte} est piloté par la machine carte
 * pure ({@link MachineEtatsCarte}) puis PROJETÉ sur le {@link PaiementStatut} générique via
 * {@link MappingStatutCarte} — jamais l'inverse, et la machine partagée n'est jamais modifiée.</p>
 *
 * <p><b>Vérité serveur (décision §1.2)</b> : le résultat 3DS/autorisation n'est JAMAIS déclaré par le
 * client. {@link #finaliser(UUID)} lit le statut AUTORITATIF auprès du PSP ({@code recupererStatut}) sous
 * <b>verrou pessimiste</b> — deux finalisations (ou finalisation ⇄ webhook) concurrentes ne produisent
 * qu'UNE capture (§7.2).</p>
 *
 * <p><b>PCI</b> : aucun PAN/CVV n'entre ici ; seuls des tokens et métadonnées non sensibles sont
 * manipulés ; le {@code challengeRef} n'est stocké que HACHÉ.</p>
 */
@Service
public class ServiceCarte {

    /** Fenêtre de fraîcheur anti-rejeu : un webhook plus vieux (ou daté dans le futur) que cela est rejeté. */
    private static final Duration FENETRE_FRAICHEUR = Duration.ofMinutes(5);

    private final PaiementRepository paiements;
    private final TransitionPaiementRepository transitions;
    private final CarteTransactionRepository carteTransactions;
    private final CarteRepository cartes;
    private final CarteRemboursementRepository remboursements;
    private final CarteEvenementWebhookRepository webhooks;
    private final RegistrePasserellesCarte passerelles;
    private final ServiceIdempotence idempotence;
    private final AntiRejeuWebhook antiRejeu;
    private final ServiceAudit audit;
    private final ServiceFacturation facturation;
    private final ObjectMapper json;
    private final ServiceCarte self;

    public ServiceCarte(PaiementRepository paiements,
                        TransitionPaiementRepository transitions,
                        CarteTransactionRepository carteTransactions,
                        CarteRepository cartes,
                        CarteRemboursementRepository remboursements,
                        CarteEvenementWebhookRepository webhooks,
                        RegistrePasserellesCarte passerelles,
                        ServiceIdempotence idempotence,
                        AntiRejeuWebhook antiRejeu,
                        ServiceAudit audit,
                        ServiceFacturation facturation,
                        ObjectMapper json,
                        @Lazy ServiceCarte self) {
        this.paiements = paiements;
        this.transitions = transitions;
        this.carteTransactions = carteTransactions;
        this.cartes = cartes;
        this.remboursements = remboursements;
        this.webhooks = webhooks;
        this.passerelles = passerelles;
        this.idempotence = idempotence;
        this.antiRejeu = antiRejeu;
        this.audit = audit;
        this.facturation = facturation;
        this.json = json;
        // Auto-référence proxifiée : self.executer…(…) déclenche bien la transaction (l'appel this.… la court-circuiterait).
        this.self = self;
    }

    // ---------------------------------------------------------------------------------------------
    // Initiation
    // ---------------------------------------------------------------------------------------------

    /**
     * Initie un paiement carte de façon idempotente (verrou Redis + unicité PG de {@code idempotency_key}).
     * Un rejeu de la même clé renvoie l'état déjà produit sans réappeler la passerelle ({@code rejoue=true}).
     */
    public ResultatCarte initier(CommandeCarte cmd, String cleIdempotence) {
        var existant = paiements.findByIdempotencyKey(cleIdempotence);
        if (existant.isPresent()) {
            return rejeu(existant.get());
        }
        if (!idempotence.acquerir(cleIdempotence)) {
            return paiements.findByIdempotencyKey(cleIdempotence)
                    .map(this::rejeu)
                    .orElseThrow(() -> new ConflitIdempotenceException(cleIdempotence));
        }
        try {
            return self.executerInitiation(cmd, cleIdempotence);
        } finally {
            idempotence.liberer(cleIdempotence);
        }
    }

    @Transactional
    public ResultatCarte executerInitiation(CommandeCarte cmd, String cleIdempotence) {
        var deja = paiements.findByIdempotencyKey(cleIdempotence);
        if (deja.isPresent()) {
            return rejeu(deja.get());
        }

        PasserelleCarte psp = passerelles.pour(cmd.psp());
        Paiement paiement = paiements.save(new Paiement(
                cleIdempotence, cmd.correlationId(), cmd.montant().valeur(), cmd.montant().devise().code(),
                "carte", cmd.objet(), null, cmd.etablissementRef(), cmd.patientRef()));
        if (cmd.factureId() != null) {
            paiement.setFactureId(cmd.factureId());
        }
        auditer("CardPaymentInitiated", paiement, Map.of("psp", cmd.psp(), "montant", paiement.getMontant()));

        ResultatInitiation issue = psp.initier(new DemandeCarte(
                cmd.referenceClient(), cmd.montant(), cmd.utilisateurRef(), cmd.returnUrl()));

        return switch (issue) {
            case ResultatInitiation.Frictionless f -> {
                CarteTransaction tx = creerTransaction(paiement, cmd, psp, f.refPasserelle());
                autoriserEtCapturer(paiement, tx);
                enrolerSiDemande(cmd, tx, f.marque(), f.last4(), f.expMois(), f.expAnnee(), f.ntid());
                yield snapshot(paiement, tx, false);
            }
            case ResultatInitiation.DefiRequis d -> {
                CarteTransaction tx = creerTransaction(paiement, cmd, psp, d.refPasserelle());
                tx.setChallengeRefHash(sha256(d.challengeRef()));
                tx.setChallengeExpireLe(d.expireLe());
                appliquer(paiement, tx, INTERACTION_CLIENT_REQUISE, "Défi 3DS requis");
                auditer("CardChallengeRequired", paiement, Map.of("psp", cmd.psp()));
                yield new ResultatCarte(paiement.getId(), tx.getId(), paiement.getStatut(),
                        ActionClient.DEFI_3DS, d.challengeRef(), null, d.expireLe(), null, false);
            }
            case ResultatInitiation.RedirectionRequise r -> {
                CarteTransaction tx = creerTransaction(paiement, cmd, psp, r.refPasserelle());
                tx.setChallengeExpireLe(r.expireLe());
                appliquer(paiement, tx, INTERACTION_CLIENT_REQUISE, "Redirection hébergée requise");
                auditer("CardRedirectRequired", paiement, Map.of("psp", cmd.psp()));
                yield new ResultatCarte(paiement.getId(), tx.getId(), paiement.getStatut(),
                        ActionClient.REDIRECTION, null, r.urlPaiement(), r.expireLe(), null, false);
            }
            case ResultatInitiation.Refusee ref -> {
                // Refus à l'initiation : réf. de passerelle synthétique (UNIQUE(paiement_id) + NOT NULL).
                CarteTransaction tx = creerTransaction(paiement, cmd, psp, "REFUS-" + paiement.getId());
                tx.setCodeRefus(ref.codeRefus());
                appliquer(paiement, tx, REFUS, "Refus à l'initiation");
                auditer("CardPaymentRefused", paiement,
                        Map.of("psp", cmd.psp(), "codeRefus", nz(ref.codeRefus())));
                yield new ResultatCarte(paiement.getId(), tx.getId(), paiement.getStatut(),
                        ActionClient.REFUSEE, null, null, null, ref.motifPublic(), false);
            }
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Finalisation (vérité serveur) + remboursement
    // ---------------------------------------------------------------------------------------------

    /**
     * Finalise une transaction en attente d'interaction : lit le statut AUTORITATIF auprès du PSP (jamais
     * le client, §1.2) sous verrou pessimiste, puis avance la machine. Idempotent : si la transaction n'est
     * plus « en attente client » (déjà capturée par un webhook, refusée, expirée), renvoie l'état courant.
     */
    @Transactional
    public ResultatCarte finaliser(UUID paiementId) {
        CarteTransaction reference = carteTransactions.findByPaiementId(paiementId)
                .orElseThrow(() -> new CarteTransactionIntrouvableException(paiementId.toString()));
        CarteTransaction tx = carteTransactions.verrouiller(reference.getId())
                .orElseThrow(() -> new CarteTransactionIntrouvableException(paiementId.toString()));
        Paiement paiement = paiements.findById(paiementId)
                .orElseThrow(() -> new PaiementIntrouvableException(paiementId.toString()));

        if (tx.getStatutCarte() != StatutCarte.EN_ATTENTE_CLIENT) {
            return snapshot(paiement, tx, false); // déjà résolu ailleurs → idempotent
        }

        StatutPasserelle statut = passerelles.pour(tx.getPsp()).recupererStatut(tx.getRefPasserelle());
        appliquerIssueAutoritative(paiement, tx, statut.issue(), statut.codeRefus(), "finalisation");
        return snapshot(paiement, tx, false);
    }

    /**
     * Applique l'issue AUTORITATIVE du PSP (lue via {@code recupererStatut} OU reçue par webhook signé — même
     * code, une seule vérité §1.2). L'appelant garantit que {@code tx} est verrouillée et « en attente client ».
     */
    private void appliquerIssueAutoritative(Paiement paiement, CarteTransaction tx, IssuePasserelle issue,
                                            String codeRefus, String source) {
        switch (issue) {
            case AUTORISE, CAPTURE -> autoriserEtCapturer(paiement, tx);
            case REFUSE -> {
                tx.setCodeRefus(codeRefus);
                appliquer(paiement, tx, REFUS, "Refus (" + source + ")");
                auditer("CardPaymentRefused", paiement,
                        Map.of("psp", tx.getPsp(), "codeRefus", nz(codeRefus), "source", source));
            }
            case EXPIRE -> {
                appliquer(paiement, tx, EXPIRATION, "Interaction expirée (" + source + ")");
                auditer("CardChallengeExpired", paiement, Map.of("psp", tx.getPsp(), "source", source));
            }
            case EN_ATTENTE -> {
                // Toujours en attente côté PSP (redirection non revenue) : aucun mouvement.
            }
        }
    }

    /**
     * Rembourse (total ou partiel) vers la carte d'origine (interdit #10). Idempotent via verrou Redis sur
     * la clé fournie ; le cumul remboursé ne peut jamais dépasser le capturé (frontière : contrôle backend).
     */
    public ResultatCarte rembourser(UUID paiementId, long valeur, String codeDevise, String motif,
                                    String cleIdempotence) {
        String verrou = "carte-refund:" + cleIdempotence;
        if (!idempotence.acquerir(verrou)) {
            throw new ConflitIdempotenceException(cleIdempotence);
        }
        try {
            return self.executerRemboursement(paiementId, valeur, codeDevise, motif);
        } finally {
            idempotence.liberer(verrou);
        }
    }

    @Transactional
    public ResultatCarte executerRemboursement(UUID paiementId, long valeur, String codeDevise, String motif) {
        CarteTransaction reference = carteTransactions.findByPaiementId(paiementId)
                .orElseThrow(() -> new CarteTransactionIntrouvableException(paiementId.toString()));
        CarteTransaction tx = carteTransactions.verrouiller(reference.getId())
                .orElseThrow(() -> new CarteTransactionIntrouvableException(paiementId.toString()));
        Paiement paiement = paiements.findById(paiementId)
                .orElseThrow(() -> new PaiementIntrouvableException(paiementId.toString()));

        Devise devise = Devise.depuisCode(codeDevise);
        if (devise != Devise.depuisCode(tx.getDevise())) {
            throw new OperationCarteInvalideException("Devise du remboursement incohérente");
        }
        // Refuse d'appeler la passerelle si l'état n'admet pas de remboursement (évite un appel PSP inutile).
        if (!MachineEtatsCarte.estAutorisee(tx.getStatutCarte(), REMBOURSEMENT_PARTIEL)) {
            throw new OperationCarteInvalideException("Transaction non remboursable dans l'état courant");
        }
        Montant demande = Montant.de(valeur, devise); // rejette le négatif au niveau du type
        if (demande.estNul()) {
            throw new OperationCarteInvalideException("Montant de remboursement nul");
        }
        Montant capture = Montant.de(tx.getMontantCapture(), devise);
        Montant cumule = Montant.de(tx.getMontantRembourse(), devise).plus(demande);
        if (cumule.superieurA(capture)) {
            throw new OperationCarteInvalideException("Remboursement cumulé supérieur au capturé");
        }

        ResultatRemboursement res = passerelles.pour(tx.getPsp())
                .rembourser(tx.getRefPasserelle(), demande, motif);
        if (!res.reussi()) {
            throw new OperationCarteInvalideException("Remboursement refusé par la passerelle");
        }
        remboursements.save(new CarteRemboursement(tx.getId(), tx.getPsp(), res.referenceOperateur(),
                demande.valeur(), devise.code(), "REUSSI", motif, paiement.getEtablissementRef()));
        tx.setMontantRembourse(cumule.valeur());

        boolean total = cumule.valeur() == capture.valeur();
        appliquer(paiement, tx, total ? REMBOURSEMENT_TOTAL : REMBOURSEMENT_PARTIEL,
                total ? "Remboursement total" : "Remboursement partiel");
        auditer("CardRefunded", paiement, Map.of(
                "montant", demande.valeur(), "cumule", cumule.valeur(), "total", total, "psp", tx.getPsp()));
        return snapshot(paiement, tx, false);
    }

    // ---------------------------------------------------------------------------------------------
    // Webhook (source de vérité §7.3)
    // ---------------------------------------------------------------------------------------------

    /**
     * Traite un webhook entrant (CDC_06 §7.3). Ordre STRICT : <b>1)</b> vérification de signature HMAC sur le
     * CORPS BRUT AVANT toute désérialisation ; <b>2)</b> parsing normalisé ; <b>3)</b> contrôle de fraîcheur
     * (horodatage signé, fenêtre ±5 min) ; <b>4)</b> anti-rejeu Redis (chemin rapide) ; <b>5)</b> application
     * sous verrou pessimiste + déduplication base {@code UNIQUE(psp, evenement_id)} (autorité ultime).
     *
     * <p>Tout échec des contrôles 1-3 lève un {@link WebhookInvalideException} GÉNÉRIQUE (401, anti-fuite) :
     * on ne révèle jamais lequel a échoué. Un rejeu (déjà vu / déjà en base) est idempotent (aucune action).</p>
     */
    public void appliquerWebhook(String psp, byte[] corpsBrut, Map<String, String> entetes) {
        PasserelleCarte passerelle = passerelles.pour(psp);

        // 1) Signature AVANT désérialisation (on ne fait confiance à aucun octet non authentifié).
        if (corpsBrut == null || !passerelle.verifierSignature(corpsBrut, entetes)) {
            throw new WebhookInvalideException();
        }
        // 2) Parsing (corps authentifié).
        EvenementWebhook evt;
        try {
            evt = passerelle.parserEvenement(corpsBrut);
        } catch (RuntimeException e) {
            throw new WebhookInvalideException();
        }
        // 3) Fraîcheur : l'horodatage est dans le corps signé → un attaquant ne peut pas le forger.
        if (evt.evenementId() == null || evt.horodatage() == null
                || Duration.between(evt.horodatage(), Instant.now()).abs().compareTo(FENETRE_FRAICHEUR) > 0) {
            throw new WebhookInvalideException();
        }
        // 4) Anti-rejeu Redis (chemin rapide ; nonce posé seulement après commit → pas de « brûlure »).
        if (antiRejeu.dejaVu(psp, evt.evenementId())) {
            return;
        }
        // 5) Application transactionnelle (déduplication base = autorité). Un doublon concurrent viole
        //    UNIQUE(psp, evenement_id) → rejeu idempotent, jamais une double application.
        try {
            self.appliquerEvenement(psp, evt);
        } catch (org.springframework.dao.DataIntegrityViolationException doublonConcurrent) {
            return;
        }
        antiRejeu.marquer(psp, evt.evenementId());
    }

    @Transactional
    public void appliquerEvenement(String psp, EvenementWebhook evt) {
        if (webhooks.existsByPspAndEvenementId(psp, evt.evenementId())) {
            return; // déjà traité (rejeu séquentiel) → idempotent
        }
        CarteEvenementWebhook enregistrement = webhooks.save(new CarteEvenementWebhook(
                psp, evt.evenementId(), nz(evt.type()), "RECU", chargeMasquee(evt)));

        var reference = carteTransactions.findByPspAndRefPasserelle(psp, evt.refPasserelle());
        if (reference.isEmpty()) {
            enregistrement.marquerTraite("ORPHELIN", Instant.now()); // consigné, aucune transaction connue
            return;
        }
        CarteTransaction tx = carteTransactions.verrouiller(reference.get().getId())
                .orElseThrow(() -> new CarteTransactionIntrouvableException(reference.get().getId().toString()));
        Paiement paiement = paiements.findById(tx.getPaiementId())
                .orElseThrow(() -> new PaiementIntrouvableException(tx.getPaiementId().toString()));

        // Sérialisé avec finaliser (même verrou) : n'agit que si encore en attente → une seule capture.
        if (tx.getStatutCarte() == StatutCarte.EN_ATTENTE_CLIENT) {
            appliquerIssueAutoritative(paiement, tx, evt.issue(), evt.codeRefus(), "webhook");
        }
        enregistrement.marquerTraite("TRAITE", Instant.now());
    }

    /** Snapshot JSONB des seuls champs NON sensibles de l'événement (frontière PCI : jamais le corps brut). */
    private String chargeMasquee(EvenementWebhook evt) {
        Map<String, Object> masque = new LinkedHashMap<>();
        masque.put("type", evt.type());
        masque.put("refPasserelle", evt.refPasserelle());
        masque.put("issue", evt.issue() == null ? null : evt.issue().name());
        masque.put("marque", evt.marque());
        masque.put("last4", evt.last4());
        masque.put("codeRefus", evt.codeRefus());
        try {
            return json.writeValueAsString(masque);
        } catch (com.fasterxml.jackson.core.JsonProcessingException e) {
            throw new IllegalStateException("Sérialisation du snapshot webhook impossible", e);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Expiration (jobs planifiés §8)
    // ---------------------------------------------------------------------------------------------

    /**
     * Expire les défis/redirections en attente dont le délai est dépassé (EN_ATTENTE_CLIENT → EXPIREE).
     * Chaque transaction est reverrouillée + revérifiée sous verrou (une finalisation/webhook concurrent a
     * pu la résoudre entre-temps → aucune expiration). Détection déterministe ; aucune règle métier front.
     */
    @Transactional
    public int expirerDefisEchus() {
        Instant maintenant = Instant.now();
        int n = 0;
        for (CarteTransaction ref : carteTransactions
                .findByStatutCarteAndChallengeExpireLeBefore(StatutCarte.EN_ATTENTE_CLIENT, maintenant)) {
            if (expirer(ref.getId(), StatutCarte.EN_ATTENTE_CLIENT, maintenant, true)) {
                n++;
            }
        }
        return n;
    }

    /**
     * Expire les autorisations non capturées dont le délai est dépassé (AUTORISEE → EXPIREE). Dormant tant
     * que la capture est synchrone (auto-capture) ; « prêt à activer » pour la capture différée future.
     */
    @Transactional
    public int expirerAutorisationsEchues() {
        Instant maintenant = Instant.now();
        int n = 0;
        for (CarteTransaction ref : carteTransactions
                .findByStatutCarteAndAutorisationExpireLeBefore(StatutCarte.AUTORISEE, maintenant)) {
            if (expirer(ref.getId(), StatutCarte.AUTORISEE, maintenant, false)) {
                n++;
            }
        }
        return n;
    }

    private boolean expirer(UUID txId, StatutCarte attendu, Instant maintenant, boolean surDefi) {
        CarteTransaction tx = carteTransactions.verrouiller(txId).orElse(null);
        if (tx == null || tx.getStatutCarte() != attendu) {
            return false; // résolu par une finalisation/webhook concurrent → rien à faire
        }
        Instant echeance = surDefi ? tx.getChallengeExpireLe() : tx.getAutorisationExpireLe();
        if (echeance == null || echeance.isAfter(maintenant)) {
            return false; // pas (plus) échu
        }
        Paiement paiement = paiements.findById(tx.getPaiementId())
                .orElseThrow(() -> new PaiementIntrouvableException(tx.getPaiementId().toString()));
        appliquer(paiement, tx, EXPIRATION, "Délai dépassé (job)");
        auditer("CardChallengeExpired", paiement, Map.of("psp", tx.getPsp(), "source", "job"));
        return true;
    }

    // ---------------------------------------------------------------------------------------------
    // Débit récurrent MIT (mandats §5.4)
    // ---------------------------------------------------------------------------------------------

    /**
     * Débite une carte enrôlée en MIT (Merchant-Initiated, §5.4) : PAIEMENT SIMULÉ (FT5), sans porteur ni
     * 3DS. Crée un {@link Paiement} + une {@link CarteTransaction} et, si la passerelle autorise, capture
     * immédiatement (auto-capture, comme le frictionless). Idempotent par {@code idempotency_key} du
     * paiement : un rejeu de la même clé (même mandat/même séquence) renvoie l'état déjà produit sans
     * réappeler la passerelle. L'appelant (ServiceMandat) fournit une clé déterministe par échéance.
     *
     * <p><b>Frontière</b> : le résultat est décidé par la passerelle ({@link PasserelleCarte#debiterRecurrent})
     * — jamais par l'appelant (§1.2). La machine carte partagée n'est pas modifiée (chemin frictionless réutilisé).</p>
     */
    @Transactional
    public ResultatDebitMandat debiterMandat(Carte carte, long montant, String codeDevise, ci.masante.payment.domain.model.ObjetPaiement objet,
                                             String etablissementRef, String patientRef, String referenceMandat,
                                             String cleIdempotence) {
        var deja = paiements.findByIdempotencyKey(cleIdempotence);
        if (deja.isPresent()) {
            CarteTransaction txDeja = carteTransactions.findByPaiementId(deja.get().getId()).orElse(null);
            return new ResultatDebitMandat(deja.get().getId(), txDeja == null ? null : txDeja.getId(),
                    deja.get().getStatut(), deja.get().getStatut() == PaiementStatut.SUCCESS,
                    txDeja == null ? null : txDeja.getCodeRefus(), true);
        }

        PasserelleCarte psp = passerelles.pour(carte.getPsp());
        Devise devise = Devise.depuisCode(codeDevise);
        Paiement paiement = paiements.save(new Paiement(cleIdempotence, referenceMandat, montant, codeDevise,
                "carte", objet, null, etablissementRef, patientRef));
        auditer("MandateDebitInitiated", paiement,
                Map.of("psp", carte.getPsp(), "montant", montant, "mandat", nz(referenceMandat)));

        ResultatDebitRecurrent r = psp.debiterRecurrent(
                carte.getToken(), carte.getNetworkTransactionId(), Montant.de(montant, devise), referenceMandat);

        CarteTransaction tx = new CarteTransaction(paiement.getId(), carte.getPsp(), psp.modalite(),
                r.refPasserelle(), StatutCarte.CREEE, montant, codeDevise);
        tx.setCarteId(carte.getId());
        paiement.setProviderRef(r.refPasserelle());
        carteTransactions.save(tx);

        if (r.reussi()) {
            // MIT : pas d'authentification porteur ; on avance la machine puis auto-capture.
            appliquer(paiement, tx, AUTHENTIFICATION_REUSSIE, "MIT : sans interaction porteur");
            appliquer(paiement, tx, AUTORISATION_OK, "Autorisation MIT accordée");
            tx.setMontantCapture(montant);
            appliquer(paiement, tx, CAPTURE_OK, "Fonds capturés (MIT)");
            paiement.setConfirmedAt(Instant.now());
            paiements.save(paiement);
            auditer("MandateDebitCaptured", paiement, Map.of("montant", montant, "psp", carte.getPsp()));
        } else {
            tx.setCodeRefus(r.codeRefus());
            appliquer(paiement, tx, REFUS, "Refus MIT récurrent");
            auditer("MandateDebitRefused", paiement,
                    Map.of("psp", carte.getPsp(), "codeRefus", nz(r.codeRefus())));
        }
        return new ResultatDebitMandat(paiement.getId(), tx.getId(), paiement.getStatut(),
                r.reussi(), r.codeRefus(), false);
    }

    // ---------------------------------------------------------------------------------------------
    // Consultation + vault
    // ---------------------------------------------------------------------------------------------

    @Transactional(readOnly = true)
    public ResultatCarte consulter(UUID paiementId) {
        CarteTransaction tx = carteTransactions.findByPaiementId(paiementId)
                .orElseThrow(() -> new CarteTransactionIntrouvableException(paiementId.toString()));
        Paiement paiement = paiements.findById(paiementId)
                .orElseThrow(() -> new PaiementIntrouvableException(paiementId.toString()));
        return snapshot(paiement, tx, false);
    }

    @Transactional(readOnly = true)
    public List<Carte> listerCartes(String utilisateurRef) {
        return cartes.findByUtilisateurRefAndSupprimeLeIsNullOrderByCreeLeDesc(utilisateurRef);
    }

    @Transactional
    public void supprimerCarte(String utilisateurRef, UUID carteId) {
        Carte carte = cartes.findByIdAndUtilisateurRefAndSupprimeLeIsNull(carteId, utilisateurRef)
                .orElseThrow(() -> new CarteIntrouvableException(carteId.toString()));
        carte.marquerSupprimee(Instant.now());
        cartes.save(carte);
        auditerCarte("CardDeleted", carte);
    }

    @Transactional
    public void definirParDefaut(String utilisateurRef, UUID carteId) {
        Carte cible = cartes.findByIdAndUtilisateurRefAndSupprimeLeIsNull(carteId, utilisateurRef)
                .orElseThrow(() -> new CarteIntrouvableException(carteId.toString()));
        for (Carte c : cartes.findByUtilisateurRefAndSupprimeLeIsNullOrderByCreeLeDesc(utilisateurRef)) {
            if (c.isParDefaut() && !c.getId().equals(cible.getId())) {
                c.definirParDefaut(false);
                cartes.save(c);
            }
        }
        cible.definirParDefaut(true);
        cartes.save(cible);
        auditerCarte("CardSetDefault", cible);
    }

    // ---------------------------------------------------------------------------------------------
    // Internes
    // ---------------------------------------------------------------------------------------------

    private CarteTransaction creerTransaction(Paiement paiement, CommandeCarte cmd, PasserelleCarte psp,
                                              String refPasserelle) {
        CarteTransaction tx = new CarteTransaction(paiement.getId(), psp.psp(), psp.modalite(), refPasserelle,
                StatutCarte.CREEE, cmd.montant().valeur(), cmd.montant().devise().code());
        paiement.setProviderRef(refPasserelle);
        return carteTransactions.save(tx);
    }

    /** Chemin AUTORISE : authentifie → autorise → capture (§9 : jamais de capture sans autorisation valide). */
    private void autoriserEtCapturer(Paiement paiement, CarteTransaction tx) {
        appliquer(paiement, tx, AUTHENTIFICATION_REUSSIE, "Authentification réussie");
        appliquer(paiement, tx, AUTORISATION_OK, "Autorisation bancaire accordée");

        Montant montant = Montant.de(tx.getMontant(), tx.getDevise());
        ResultatCapture capture = passerelles.pour(tx.getPsp()).capturer(tx.getRefPasserelle(), montant);
        if (!capture.reussi()) {
            throw new OperationCarteInvalideException("Capture refusée par la passerelle");
        }
        tx.setMontantCapture(tx.getMontant());
        appliquer(paiement, tx, CAPTURE_OK, "Fonds capturés");
        paiement.setConfirmedAt(Instant.now());
        paiements.save(paiement);

        // Règlement d'une facture (§7.3) : impute le montant capturé. Facture introuvable → rollback total.
        if (paiement.getFactureId() != null) {
            facturation.enregistrerReglement(paiement.getFactureId(), tx.getMontantCapture());
        }
        auditer("CardPaymentCaptured", paiement, Map.of("montant", tx.getMontantCapture(), "psp", tx.getPsp()));
    }

    /**
     * Applique un événement à la machine carte, persiste le sous-état, puis PROJETTE sur la machine
     * générique : si le {@link PaiementStatut} projeté change, la transition générique est vérifiée et
     * consignée dans {@code payment_transitions} (sinon no-op — ex. capture→remboursement partiel restent
     * SUCCESS). Garantit qu'on ne force jamais une transition interdite de la machine partagée (interdit #1).
     */
    private void appliquer(Paiement paiement, CarteTransaction tx, EvenementCarte evenement, String raison) {
        StatutCarte de = tx.getStatutCarte();
        StatutCarte vers = MachineEtatsCarte.transition(de, evenement); // lève si (état, événement) illégal
        tx.setStatutCarte(vers);
        carteTransactions.save(tx);

        PaiementStatut genDe = MappingStatutCarte.versPaiement(de);
        PaiementStatut genVers = MappingStatutCarte.versPaiement(vers);
        if (genDe != genVers) {
            MachineEtatsPaiement.verifier(genDe, genVers);
            transitions.save(new TransitionPaiement(paiement.getId(), genDe, genVers, raison));
            paiement.setStatut(genVers);
            paiements.save(paiement);
        }
    }

    /** Enrôle la carte au vault après autorisation (chemin frictionless : cmd + métadonnées disponibles). */
    private void enrolerSiDemande(CommandeCarte cmd, CarteTransaction tx, String marque, String last4,
                                  int expMois, int expAnnee, String ntid) {
        if (!cmd.enregistrerCarte()) {
            return;
        }
        // Empreinte = métadonnées NON sensibles du PSP (jamais dérivée du PAN, interdit #4).
        String empreinte = tx.getPsp() + ":" + marque + ":" + last4 + ":" + expMois + "/" + expAnnee;
        Carte carte = cartes.findByUtilisateurRefAndEmpreinteAndSupprimeLeIsNull(cmd.utilisateurRef(), empreinte)
                .orElseGet(() -> {
                    // token de vault RÉUTILISABLE et UNIQUE par carte (en prod : renvoyé par le PSP). La
                    // référence client est une réf. de SESSION à usage unique — la réutiliser comme token
                    // violerait UNIQUE(psp, token) dès la 2e carte (vault mono-carte). Token = valeur propre.
                    String tokenVault = "tokv_" + UUID.randomUUID();
                    Carte nouvelle = new Carte(cmd.utilisateurRef(), tx.getPsp(), null, tokenVault,
                            empreinte, marque, last4, expMois, expAnnee, ntid);
                    boolean premiere = cartes
                            .findByUtilisateurRefAndSupprimeLeIsNullOrderByCreeLeDesc(cmd.utilisateurRef())
                            .isEmpty();
                    nouvelle.definirParDefaut(premiere);
                    return cartes.save(nouvelle);
                });
        tx.setCarteId(carte.getId());
        carteTransactions.save(tx);
        auditerCarte("CardEnrolled", carte);
    }

    private ResultatCarte snapshot(Paiement paiement, CarteTransaction tx, boolean rejoue) {
        return new ResultatCarte(paiement.getId(), tx.getId(), paiement.getStatut(),
                actionDepuis(tx), null, null, tx.getChallengeExpireLe(), null, rejoue);
    }

    private ResultatCarte rejeu(Paiement paiement) {
        CarteTransaction tx = carteTransactions.findByPaiementId(paiement.getId()).orElse(null);
        if (tx == null) {
            return new ResultatCarte(paiement.getId(), null, paiement.getStatut(),
                    ActionClient.AUCUNE, null, null, null, null, true);
        }
        return snapshot(paiement, tx, true);
    }

    /** Action à exposer d'après le sous-état — sans révéler le sous-état lui-même (interdit #8). */
    private static ActionClient actionDepuis(CarteTransaction tx) {
        return switch (tx.getStatutCarte()) {
            case EN_ATTENTE_CLIENT -> switch (tx.getModalite()) {
                case TOKENISEE -> ActionClient.DEFI_3DS;
                case REDIRIGEE -> ActionClient.REDIRECTION;
            };
            case REFUSEE -> ActionClient.REFUSEE;
            default -> ActionClient.AUCUNE;
        };
    }

    private void auditer(String evenement, Paiement paiement, Map<String, Object> extra) {
        Map<String, Object> payload = new HashMap<>(extra);
        payload.put("statut", paiement.getStatut().name());
        audit.enregistrer(evenement, "card_payment", paiement.getId().toString(), payload);
    }

    private void auditerCarte(String evenement, Carte carte) {
        audit.enregistrer(evenement, "card", carte.getId().toString(), Map.of(
                "marque", nz(carte.getMarque()), "last4", nz(carte.getLast4()),
                "utilisateur", nz(carte.getUtilisateurRef())));
    }

    private static String sha256(String valeur) {
        if (valeur == null) {
            return null;
        }
        try {
            byte[] digest = MessageDigest.getInstance("SHA-256").digest(valeur.getBytes(StandardCharsets.UTF_8));
            StringBuilder hex = new StringBuilder(64);
            for (byte b : digest) {
                hex.append(Character.forDigit((b >> 4) & 0xF, 16));
                hex.append(Character.forDigit(b & 0xF, 16));
            }
            return hex.toString();
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException("SHA-256 indisponible", e);
        }
    }

    private static String nz(String v) {
        return v == null ? "" : v;
    }
}
