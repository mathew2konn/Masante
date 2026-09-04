package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.carte.simulated.SignatureHmac;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.ClientGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.MappeurStatutGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.MontantNonEntierException;
import ci.masante.payment.domain.model.CarteEvenementWebhook;
import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.IdentifiantMarchand;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.StatutGeniusPay;
import ci.masante.payment.domain.model.TransitionPaiement;
import ci.masante.payment.domain.statemachine.MachineEtatsGeniusPay;
import ci.masante.payment.domain.statemachine.MachineEtatsPaiement;
import ci.masante.payment.repository.CarteEvenementWebhookRepository;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import ci.masante.payment.repository.IdentifiantMarchandRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Propagation;
import org.springframework.transaction.annotation.Transactional;

import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.Instant;
import java.util.HashMap;
import java.util.HexFormat;
import java.util.Map;
import java.util.Optional;

/**
 * Réception et traitement des webhooks GeniusPay — <b>le cœur du lot</b>.
 *
 * <p>Le webhook est la <b>seule source de vérité</b> du statut (D5). Ni la réponse d'initiation, ni
 * le retour du patient sur {@code success_url} ne confirment quoi que ce soit : le premier dit
 * seulement qu'un lien a été créé, le second qu'un navigateur est revenu sur une page.</p>
 *
 * <h2>La séparation qui rend le tout tenable</h2>
 * <p>La réception <b>vérifie, enregistre et rend la main</b>. Elle n'applique rien. C'est le relais
 * planifié qui applique. Le prestataire attend une réponse en moins de dix secondes et réessaie cinq
 * fois sur un 5xx ou un délai dépassé : un traitement synchrone lent produirait des renvois
 * concurrents, cause n°1 des doubles écritures en paiement.</p>
 *
 * <h2>Ce qui est vérifié, dans cet ordre exact</h2>
 * <ol>
 *   <li>Présence des en-têtes → {@code 400}.</li>
 *   <li>Résolution du secret <b>par le slug de l'URL</b>, jamais par le corps.</li>
 *   <li>Signature HMAC sur {@code horodatage + "." + octets bruts}, comparée en <b>temps
 *       constant</b> → {@code 401} au corps vide de détail.</li>
 *   <li>Fraîcheur de l'horodatage → {@code 400}.</li>
 *   <li>Environnement ({@code sandbox}) → rejet. <b>Ajout délibéré</b>, absent de la documentation :
 *       sans lui, un webhook « live » pourrait solder une facture de test, ou l'inverse.</li>
 *   <li>Enregistrement <b>dans tous les cas</b>, y compris rejeté — un rejet silencieux rend
 *       l'incident invisible, et c'est précisément l'incident qu'on voudra retrouver.</li>
 * </ol>
 *
 * <h2>Pourquoi la signature se calcule sur les octets reçus</h2>
 * <p>L'exemple PHP de la documentation officielle calcule la signature sur un JSON <b>ré-encodé</b>.
 * Le payload contient {@code "amount": 10000.00} : décodé puis ré-encodé, il devient {@code 10000.0}.
 * La chaîne diffère d'un octet, la signature échoue. Seul l'exemple Java du guide, qui utilise le
 * corps brut, fait foi.</p>
 */
@Service
public class ServiceWebhookGeniusPay {

    private static final Logger log = LoggerFactory.getLogger(ServiceWebhookGeniusPay.class);

    public static final String RECU = "RECU";
    public static final String TRAITE = "TRAITE";
    public static final String REJETE_SIGNATURE = "REJETE_SIGNATURE";
    public static final String REJETE_HORODATAGE = "REJETE_HORODATAGE";
    public static final String REJETE_ENVIRONNEMENT = "REJETE_ENV";
    public static final String IGNORE_DOUBLON = "IGNORE_DOUBLON";
    public static final String IGNORE_NON_GERE = "IGNORE_NON_GERE";
    public static final String ERREUR = "ERREUR";

    private final IdentifiantMarchandRepository marchands;
    private final GestionnaireSecretsMarchand secrets;
    private final CarteEvenementWebhookRepository evenements;
    private final GeniusPayTransactionRepository transactions;
    private final PaiementRepository paiements;
    private final TransitionPaiementRepository transitionsPaiement;
    private final AntiRejeuWebhook antiRejeu;
    private final ServiceAudit audit;
    private final ServiceFacturation facturation;
    private final ProprietesGeniusPay proprietes;
    private final ObjectMapper json;
    private final ServiceWebhookGeniusPay self;

    public ServiceWebhookGeniusPay(IdentifiantMarchandRepository marchands,
                                   GestionnaireSecretsMarchand secrets,
                                   CarteEvenementWebhookRepository evenements,
                                   GeniusPayTransactionRepository transactions,
                                   PaiementRepository paiements,
                                   TransitionPaiementRepository transitionsPaiement,
                                   AntiRejeuWebhook antiRejeu,
                                   ServiceAudit audit,
                                   ServiceFacturation facturation,
                                   ProprietesGeniusPay proprietes,
                                   ObjectMapper json,
                                   @org.springframework.context.annotation.Lazy ServiceWebhookGeniusPay self) {
        this.marchands = marchands;
        this.secrets = secrets;
        this.evenements = evenements;
        this.transactions = transactions;
        this.paiements = paiements;
        this.transitionsPaiement = transitionsPaiement;
        this.antiRejeu = antiRejeu;
        this.audit = audit;
        this.facturation = facturation;
        this.proprietes = proprietes;
        this.json = json;
        this.self = self;
    }

    /**
     * Point d'entrée de la réception. Rend le statut HTTP à renvoyer — jamais un message : un
     * attaquant qui sonde cette route ne doit rien apprendre de la raison du refus.
     */
    public int recevoir(String slug, byte[] corpsBrut, Map<String, String> entetes, String adresseIp) {
        String signature = SignatureHmac.entete(entetes, "X-Webhook-Signature");
        String horodatage = SignatureHmac.entete(entetes, "X-Webhook-Timestamp");
        String typeEvenement = SignatureHmac.entete(entetes, "X-Webhook-Event");

        if (corpsBrut == null || corpsBrut.length == 0
                || signature == null || horodatage == null || typeEvenement == null) {
            // Rien à enregistrer d'utile : sans corps ni en-têtes, il n'y a pas d'événement, juste du
            // bruit. On refuse sans écrire, pour ne pas offrir un moyen de remplir la table.
            return 400;
        }

        String empreinte = sha256(corpsBrut);

        // Le slug SÉLECTIONNE le secret candidat ; le HMAC DÉCIDE. Un slug inconnu est traité comme une
        // signature invalide : distinguer les deux dirait à un attaquant lesquels de ses slugs existent.
        Optional<IdentifiantMarchand> marchand = marchands.findBySlugAndActifIsTrue(slug);
        if (marchand.isEmpty() || !marchand.get().aUnSecretWebhook()) {
            self.enregistrerRejet(empreinte, typeEvenement, horodatage, REJETE_SIGNATURE,
                    "slug inconnu ou sans secret", entetes, adresseIp, corpsBrut);
            return 401;
        }

        // HMAC sur horodatage + "." + OCTETS BRUTS. Jamais sur un corps re-sérialisé.
        byte[] aSigner = concat(horodatage.getBytes(StandardCharsets.UTF_8), (byte) '.', corpsBrut);
        if (!SignatureHmac.verifier(aSigner, secrets.secretWebhook(marchand.get()), signature)) {
            self.enregistrerRejet(empreinte, typeEvenement, horodatage, REJETE_SIGNATURE,
                    "signature invalide", entetes, adresseIp, corpsBrut);
            return 401;
        }

        // À partir d'ici, et seulement à partir d'ici, le corps est digne de confiance.
        if (!horodatageFrais(horodatage)) {
            self.enregistrerRejet(empreinte, typeEvenement, horodatage, REJETE_HORODATAGE,
                    "horodatage hors fenêtre", entetes, adresseIp, corpsBrut);
            return 400;
        }

        JsonNode charge;
        try {
            charge = json.readTree(corpsBrut);
        } catch (Exception e) {
            self.enregistrerRejet(empreinte, typeEvenement, horodatage, ERREUR,
                    "corps illisible", entetes, adresseIp, corpsBrut);
            return 400;
        }

        String environnement = premierNonVide(
                SignatureHmac.entete(entetes, "X-Webhook-Environment"),
                charge.path("environment").asText(null),
                charge.path("data").path("environment").asText(null));
        if (!ProprietesGeniusPay.ENVIRONNEMENT_AUTORISE.equalsIgnoreCase(nz(environnement))) {
            self.enregistrerRejet(empreinte, typeEvenement, horodatage, REJETE_ENVIRONNEMENT,
                    "environnement " + nz(environnement), entetes, adresseIp, corpsBrut);
            return 400;
        }

        String evenementId = premierNonVide(charge.path("id").asText(null), "empreinte:" + empreinte);

        // Chemin rapide anti-rejeu (Redis). L'AUTORITÉ reste UNIQUE(psp, evenement_id) en base :
        // Redis peut tomber, la contrainte non.
        if (antiRejeu.dejaVu(AdaptateurGeniusPay.PSP, evenementId)
                || evenements.existsByPspAndEvenementId(AdaptateurGeniusPay.PSP, evenementId)) {
            return 200;
        }

        String statutInitial = MappeurStatutGeniusPay.depuisEvenement(typeEvenement).isPresent()
                ? RECU
                : IGNORE_NON_GERE;
        if (IGNORE_NON_GERE.equals(statutInitial) && !MappeurStatutGeniusPay.estConnuSansEffet(typeEvenement)) {
            // Un type que nous ne connaissons NI comme traitable NI comme volontairement ignoré. On le
            // range dans la même case, mais on le dit dans le journal : c'est ainsi qu'un événement
            // nouveau se remarque au lieu de disparaître.
            log.warn("Événement GeniusPay de type inconnu reçu : {} — enregistré sans traitement.", typeEvenement);
        }

        try {
            self.enregistrer(evenementId, typeEvenement, statutInitial, empreinte, horodatage,
                    environnement, entetes, adresseIp, corpsBrut, referenceDe(charge));
        } catch (DataIntegrityViolationException doublonConcurrent) {
            // Deux renvois simultanés du même événement : la contrainte a tranché. C'est l'idempotence
            // qui fonctionne, pas une erreur.
            return 200;
        }
        antiRejeu.marquer(AdaptateurGeniusPay.PSP, evenementId);
        return 200;
    }

    @Transactional(propagation = Propagation.REQUIRES_NEW)
    public void enregistrerRejet(String empreinte, String type, String horodatage, String statut,
                                 String motif, Map<String, String> entetes, String adresseIp, byte[] corps) {
        // Transaction PROPRE : le rejet doit être écrit même si l'appelant échoue ensuite. Un rejet
        // qu'on perd est un incident qu'on ne verra jamais.
        // L'identifiant est dérivé de l'empreinte du corps, jamais lu dans un payload non authentifié.
        String id = "rejet:" + empreinte;
        if (evenements.existsByPspAndEvenementId(AdaptateurGeniusPay.PSP, id)) {
            return;
        }
        try {
            evenements.save(new CarteEvenementWebhook(AdaptateurGeniusPay.PSP, id, nz(type), statut, "{}",
                    empreinte, enLong(horodatage), null, Boolean.FALSE, motif,
                    tentative(entetes), null, adresseIp, new String(corps, StandardCharsets.UTF_8)));
        } catch (DataIntegrityViolationException concurrent) {
            log.debug("Rejet déjà enregistré pour l'empreinte {}.", empreinte);
        }
        log.warn("Webhook GeniusPay rejeté ({}) — motif interne : {}.", statut, motif);
    }

    @Transactional
    public void enregistrer(String evenementId, String type, String statut, String empreinte,
                            String horodatage, String environnement, Map<String, String> entetes,
                            String adresseIp, byte[] corps, String referencePasserelle) {
        evenements.save(new CarteEvenementWebhook(AdaptateurGeniusPay.PSP, evenementId, type, statut, "{}",
                empreinte, enLong(horodatage), environnement, Boolean.TRUE, null,
                tentative(entetes), referencePasserelle, adresseIp,
                new String(corps, StandardCharsets.UTF_8)));
    }

    // ----------------------------------------------------------------------------------------------
    // §8.4 — Application, hors du fil de la requête.
    // ----------------------------------------------------------------------------------------------

    /**
     * Applique un événement enregistré. Appelé par le relais planifié, jamais par le contrôleur.
     *
     * <p>Le verrou est <b>pessimiste</b> sur la transaction : deux renvois concurrents se sérialisent
     * ici plutôt que de s'écraser.</p>
     */
    @Transactional
    public void appliquer(java.util.UUID evenementDbId) {
        CarteEvenementWebhook evenement = evenements.findById(evenementDbId).orElse(null);
        if (evenement == null || !RECU.equals(evenement.getStatutTraitement())) {
            return;
        }
        JsonNode charge;
        try {
            charge = json.readTree(evenement.getCorpsBrut());
        } catch (Exception e) {
            terminer(evenement, ERREUR, "corps illisible au traitement");
            return;
        }
        JsonNode data = charge.path("data").isMissingNode() ? charge : charge.path("data");

        Optional<StatutGeniusPay> cible = MappeurStatutGeniusPay.depuisEvenement(evenement.getType());
        if (cible.isEmpty()) {
            terminer(evenement, IGNORE_NON_GERE, null);
            return;
        }

        // 1. Retrouver la transaction : d'abord par la référence prestataire, SINON par
        //    metadata.order_id. Le second chemin n'est pas un filet de secours esthétique : c'est le
        //    seul moyen de rattacher une transaction restée INITIEE_INCERTAINE (§7.4.b).
        Optional<GeniusPayTransaction> trouvee = Optional.ofNullable(data.path("reference").asText(null))
                .flatMap(transactions::findByReferencePasserelle);
        if (trouvee.isEmpty()) {
            String orderId = data.path("metadata").path("order_id").asText(null);
            if (orderId != null) {
                trouvee = transactions.findByReferenceInterne(orderId);
            }
        }
        if (trouvee.isEmpty()) {
            terminer(evenement, ERREUR, "aucune transaction locale ne correspond");
            log.error("Webhook GeniusPay sans transaction correspondante (événement {}) — à examiner.",
                    evenement.getEvenementId());
            return;
        }

        GeniusPayTransaction transaction = transactions.verrouiller(trouvee.get().getId()).orElseThrow();
        Paiement paiement = paiements.findById(transaction.getPaiementId()).orElseThrow();

        // 2. CONTRÔLE DU MONTANT. Un écart n'est jamais une tolérance : c'est un incident, et la
        //    facture n'est pas soldée. Une décimale non nulle est du même ordre.
        try {
            long montantAnnonce = ClientGeniusPay.enFrancsEntiers(montantDe(data));
            if (montantAnnonce != paiement.getMontant()) {
                terminer(evenement, ERREUR,
                        "montant divergent : " + montantAnnonce + " contre " + paiement.getMontant());
                log.error("Montant divergent sur l'événement {} — AUCUNE facture soldée.",
                        evenement.getEvenementId());
                return;
            }
        } catch (MontantNonEntierException | IllegalArgumentException e) {
            terminer(evenement, ERREUR, "montant inexploitable");
            return;
        }

        // 3. Machine à états. Une transition interdite est le cas NORMAL d'un renvoi tardif :
        //    elle est classée en doublon, pas en erreur.
        StatutGeniusPay avant = transaction.getStatutGeniusPay();
        if (!MachineEtatsGeniusPay.estAutorisee(avant, cible.get())) {
            terminer(evenement, IGNORE_DOUBLON, "transition " + avant + " → " + cible.get() + " non autorisée");
            return;
        }

        // 4/5. Les frais viennent du prestataire et NE SE RECALCULENT PAS. Reconstituer « 100 FCFA
        //      + 1 % » produirait des écarts et casserait le reçu transparent promis aux partenaires.
        transaction.setStatutGeniusPay(cible.get());
        transaction.setReferencePasserelle(premierNonVide(transaction.getReferencePasserelle(),
                data.path("reference").asText(null)));
        appliquerSiPresent(data.path("fees"), transaction::setFraisPasserelle);
        appliquerSiPresent(data.path("net_amount"), transaction::setMontantNet);
        // Le webhook nomme le canal `gateway` (constaté au G2 : "gateway":"wave"), là où
        // `GET /payments/{ref}` le nomme `payment_provider`/`payment_method`. Les trois sont lus, dans
        // l'ordre du plus documenté au plus observé — sans quoi le canal restait NUL jusqu'au passage
        // de la réconciliation, alors que l'événement le portait.
        String canal = premierNonVide(data.path("payment_provider").asText(null),
                data.path("payment_method").asText(null),
                data.path("gateway").asText(null));
        if (canal != null) {
            transaction.setCanal(canal);
        }
        if (cible.get().estTerminal()) {
            transaction.setFinaliseeLe(Instant.now());
        }
        transactions.save(transaction);

        // Projection sur la machine PARTAGÉE, qui n'a pas été modifiée d'une ligne.
        PaiementStatut vise = cible.get().versStatutPartage();
        if (paiement.getStatut() != vise && MachineEtatsPaiement.estAutorisee(paiement.getStatut(), vise)) {
            transitionsPaiement.save(new TransitionPaiement(paiement.getId(), paiement.getStatut(), vise,
                    "Webhook GeniusPay " + evenement.getType()));
            if (vise == PaiementStatut.SUCCESS) {
                paiement.setConfirmedAt(Instant.now());
                paiement.setFactureId(transaction.getFactureId());
                // RÈGLEMENT DE LA FACTURE (§7.3). Le G2 a montré l'oubli : la transaction passait à
                // REUSSIE, le paiement à SUCCESS, et la facture restait EMISE — GeniusPay aurait été
                // le SEUL canal à encaisser sans solder ce qu'il encaisse, là où la carte, le wallet
                // et le mobile money le font tous. Le montant imputé est celui du PAIEMENT, jamais
                // celui annoncé par le prestataire : ils ont déjà été comparés plus haut, et c'est le
                // nôtre qui fait foi.
                if (transaction.getFactureId() != null) {
                    facturation.enregistrerReglement(transaction.getFactureId(), paiement.getMontant());
                }
            }
            // setStatut est le point d'accroche unique du canal interne (lot 6) : la notification au
            // partenaire part d'ici, au save(), sans une ligne de code de plus. Les frais de la
            // TRANSACTION (pas ceux, éventuellement absents, du webhook courant) : le webhook qui a
            // fait passer le paiement à SUCCESS n'est pas forcément celui qui portait "fees" (R4).
            paiement.setStatut(vise, transaction.getFraisPasserelle());
            paiements.save(paiement);
        }

        Map<String, Object> trace = new HashMap<>();
        trace.put("evenement", evenement.getType());
        trace.put("statutGeniusPay", cible.get().name());
        trace.put("referenceInterne", transaction.getReferenceInterne());
        audit.enregistrer("GeniusPayWebhookApplied", "payment", paiement.getId().toString(), trace);

        terminer(evenement, TRAITE, null);
    }

    /**
     * Classe un événement en erreur, dans une transaction PROPRE.
     *
     * <p>Elle doit être distincte de celle qui a échoué : c'est justement parce que la précédente a
     * été annulée que l'événement doit être marqué. Sans transaction propre, le marquage partirait
     * avec le rollback et l'événement reviendrait à l'infini.</p>
     */
    @Transactional(propagation = Propagation.REQUIRES_NEW)
    public void marquerEnErreur(java.util.UUID evenementDbId, String motif) {
        evenements.findById(evenementDbId).ifPresent(e -> terminer(e, ERREUR, motif));
    }

    private void terminer(CarteEvenementWebhook evenement, String statut, String motif) {
        if (motif != null) {
            evenement.setMotifRejet(motif);
        }
        evenement.marquerTraite(statut, Instant.now());
        evenements.save(evenement);
    }

    private void appliquerSiPresent(JsonNode noeud, java.util.function.Consumer<Long> pose) {
        if (noeud == null || noeud.isMissingNode() || noeud.isNull()) {
            return;
        }
        pose.accept(ClientGeniusPay.enFrancsEntiers(decimalDe(noeud)));
    }

    /**
     * Lit un montant qui peut arriver en NOMBRE ou en CHAÎNE.
     *
     * <p>Le prestataire envoie {@code "amount": "15000.00"} — une chaîne — là où la documentation
     * montre un nombre. Sur un nœud textuel, {@code JsonNode.decimalValue()} ne parse rien et rend
     * <b>zéro</b> : un montant réel devenait 0 sans le moindre bruit. Constaté au G2 sur un
     * {@code payment.success} authentique (§4.3, écart n°5).</p>
     *
     * <p>Ce qui n'est pas fait ici est aussi important : <b>aucune valeur par défaut</b>. Une chaîne
     * illisible lève, l'événement part en {@code ERREUR} et rien n'est soldé. Rendre 0 « pour que ça
     * passe » solderait une facture sur un montant inventé.</p>
     */
    private static BigDecimal decimalDe(JsonNode noeud) {
        if (noeud.isNumber()) {
            return noeud.decimalValue();
        }
        if (noeud.isTextual()) {
            try {
                return new BigDecimal(noeud.asText().trim());
            } catch (NumberFormatException e) {
                throw new IllegalArgumentException("Montant illisible : nœud textuel non numérique.", e);
            }
        }
        throw new IllegalArgumentException("Montant d'un type inattendu : " + noeud.getNodeType());
    }

    private static BigDecimal montantDe(JsonNode data) {
        JsonNode montant = data.path("amount");
        if (montant.isMissingNode() || montant.isNull()) {
            throw new IllegalArgumentException("Montant absent du webhook.");
        }
        return decimalDe(montant);
    }

    private static String referenceDe(JsonNode charge) {
        JsonNode data = charge.path("data").isMissingNode() ? charge : charge.path("data");
        return data.path("reference").asText(null);
    }

    private boolean horodatageFrais(String horodatage) {
        Long valeur = enLong(horodatage);
        if (valeur == null) {
            return false;
        }
        long ecart = Math.abs(Instant.now().getEpochSecond() - valeur);
        return ecart <= proprietes.getFenetreAntirejeuSecondes();
    }

    private static Integer tentative(Map<String, String> entetes) {
        String v = SignatureHmac.entete(entetes, "X-Webhook-Retry");
        try {
            return v == null ? null : Integer.valueOf(v.trim());
        } catch (NumberFormatException e) {
            return null;
        }
    }

    private static Long enLong(String v) {
        try {
            return v == null ? null : Long.valueOf(v.trim());
        } catch (NumberFormatException e) {
            return null;
        }
    }

    private static byte[] concat(byte[] a, byte separateur, byte[] b) {
        byte[] sortie = new byte[a.length + 1 + b.length];
        System.arraycopy(a, 0, sortie, 0, a.length);
        sortie[a.length] = separateur;
        System.arraycopy(b, 0, sortie, a.length + 1, b.length);
        return sortie;
    }

    private static String sha256(byte[] corps) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(corps));
        } catch (Exception e) {
            throw new IllegalStateException("SHA-256 indisponible", e);
        }
    }

    private static String premierNonVide(String... valeurs) {
        for (String v : valeurs) {
            if (v != null && !v.isBlank()) {
                return v;
            }
        }
        return null;
    }

    private static String nz(String v) {
        return v == null ? "" : v;
    }
}
