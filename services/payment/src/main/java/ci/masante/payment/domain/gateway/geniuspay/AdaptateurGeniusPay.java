package ci.masante.payment.domain.gateway.geniuspay;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.PasserellePaiement;
import ci.masante.payment.domain.gateway.RequetePaiement;
import ci.masante.payment.domain.gateway.RequeteRemboursement;
import ci.masante.payment.domain.gateway.ResultatPaiement;
import ci.masante.payment.domain.gateway.ResultatRemboursement;
import ci.masante.payment.domain.model.IdentifiantMarchand;
import ci.masante.payment.domain.model.StatutGeniusPay;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import ci.masante.payment.repository.IdentifiantMarchandRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.service.GestionnaireSecretsMarchand;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

import java.time.Instant;
import java.time.OffsetDateTime;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Optional;

/**
 * GeniusPay comme <b>implémentation supplémentaire</b> de {@link PasserellePaiement} (ADR-044 §B3).
 *
 * <p>Aucun second port n'a été créé : le port existe depuis P5.1, avec {@code payer} / {@code statut}
 * / {@code rembourser}, et cette classe s'y range comme {@code AdaptateurSimule}. Le registre la
 * choisit par {@link #supporte(String)} — aucun {@code if psp ==} nulle part.</p>
 *
 * <h2>Le canal s'appelle « geniuspay », et ce n'est pas un raccourci de nommage</h2>
 * <p>{@code AdaptateurSimule} revendique déjà {@code orange_money}, {@code wave}, {@code mtn_momo}…
 * Revendiquer les mêmes ferait dépendre le choix de passerelle de l'ordre d'injection des beans,
 * c'est-à-dire du hasard. Mais surtout, ce serait <b>faux</b> : nous n'appelons pas Orange Money,
 * nous ouvrons une page de checkout hébergée où <b>le patient</b> choisit son opérateur. L'opérateur
 * réellement utilisé nous revient ensuite dans {@code payment_provider}, et c'est lui qui est
 * enregistré. Le canal demandé dit « par où l'on passe », pas « qui encaisse ».</p>
 *
 * <h2>Ce que cette classe n'implémente pas, et pourquoi elle le dit</h2>
 * <p>{@link #rembourser} lève. Le remboursement est explicitement hors périmètre (§12) : l'état
 * {@code REMBOURSEE} existe pour qu'un webhook de remboursement puisse être enregistré, la logique de
 * déclenchement n'est pas écrite. Renvoyer un faux succès aurait été pire qu'une exception.</p>
 */
@Component
public class AdaptateurGeniusPay implements PasserellePaiement {

    public static final String CANAL = "geniuspay";
    public static final String PSP = "geniuspay";

    private final ClientGeniusPay client;
    private final IdentifiantMarchandRepository marchands;
    private final GestionnaireSecretsMarchand secrets;
    private final GeniusPayTransactionRepository transactions;
    private final PaiementRepository paiements;
    private final ProprietesGeniusPay proprietes;
    private final String libelleGenerique;

    public AdaptateurGeniusPay(ClientGeniusPay client,
                               IdentifiantMarchandRepository marchands,
                               GestionnaireSecretsMarchand secrets,
                               GeniusPayTransactionRepository transactions,
                               PaiementRepository paiements,
                               ProprietesGeniusPay proprietes,
                               @Value("${masante.payment.libelle-generique}") String libelleGenerique) {
        this.client = client;
        this.marchands = marchands;
        this.secrets = secrets;
        this.transactions = transactions;
        this.paiements = paiements;
        this.proprietes = proprietes;
        this.libelleGenerique = libelleGenerique;
    }

    @Override
    public boolean supporte(String canal) {
        return CANAL.equalsIgnoreCase(canal);
    }

    /**
     * Ouvre un checkout. <b>Un seul appel réseau, jamais deux</b> : le non-rejeu est une propriété de
     * l'appelant ({@code ServiceGeniusPay}), et rien ici ne doit l'affaiblir — pas de boucle, pas de
     * {@code @Retryable}, pas de « seconde tentative pour la robustesse ».
     */
    @Override
    public ResultatPaiement payer(RequetePaiement requete) {
        IdentifiantMarchand marchand = marchandDe(requete.etablissementRef());
        Map<String, Object> corps = corpsInitiation(requete);

        ReponsesGeniusPay.Paiement reponse = client.creerPaiement(
                marchand.getClePublique(), secrets.cleSecrete(marchand), corps, requete.referenceInterne());

        StatutGeniusPay statut = MappeurStatutGeniusPay.depuisStatutApi(reponse.status())
                // La création renvoie `status: null` en bac à sable. Un checkout créé sans statut est
                // en attente : c'est le seul état qu'on puisse en déduire sans rien inventer.
                .orElse(StatutGeniusPay.EN_ATTENTE);

        ResultatPaiement.DetailCheckout detail = new ResultatPaiement.DetailCheckout(
                reponse.checkoutUrl(),
                echeance(reponse.expiresAt()),
                reponse.fees() == null ? null : ClientGeniusPay.enFrancsEntiers(reponse.fees()),
                reponse.netAmount() == null ? null : ClientGeniusPay.enFrancsEntiers(reponse.netAmount()),
                reponse.paymentProvider() != null ? reponse.paymentProvider() : reponse.paymentMethod());

        return new ResultatPaiement(statut.versStatutPartage(), reponse.reference(),
                "Checkout GeniusPay ouvert.", detail);
    }

    /**
     * Consultation d'une transaction (réconciliation, §8.5).
     *
     * <p>Le montage A impose une résolution : une référence prestataire seule ne dit pas avec quelle
     * clé l'interroger, puisque chaque établissement a la sienne. On remonte donc la transaction, puis
     * le paiement, puis l'établissement. Une référence inconnue de notre base ne peut pas être
     * consultée — et c'est correct : nous n'avons rien à demander sur une transaction qui n'est pas
     * la nôtre.</p>
     */
    @Override
    public ResultatPaiement statut(String referenceOperateur) {
        var transaction = transactions.findByReferencePasserelle(referenceOperateur)
                .orElseThrow(() -> new GeniusPayException("TRANSACTION_INCONNUE", 404,
                        "Aucune transaction locale ne porte cette référence prestataire."));
        var paiement = paiements.findById(transaction.getPaiementId())
                .orElseThrow(() -> new GeniusPayException("PAIEMENT_INCONNU", 404,
                        "La transaction ne référence aucun paiement existant."));
        IdentifiantMarchand marchand = marchandDe(paiement.getEtablissementRef());

        Optional<ReponsesGeniusPay.Paiement> reponse =
                client.consulter(marchand.getClePublique(), secrets.cleSecrete(marchand), referenceOperateur);
        if (reponse.isEmpty()) {
            throw new GeniusPayException("TRANSACTION_NOT_FOUND", 404,
                    "Le prestataire ne connaît pas cette référence.");
        }
        ReponsesGeniusPay.Paiement p = reponse.get();
        StatutGeniusPay statut = MappeurStatutGeniusPay.depuisStatutApi(p.status())
                .orElse(transaction.getStatutGeniusPay());
        return new ResultatPaiement(statut.versStatutPartage(), referenceOperateur,
                "Statut GeniusPay consulté.",
                new ResultatPaiement.DetailCheckout(null, null,
                        p.fees() == null ? null : ClientGeniusPay.enFrancsEntiers(p.fees()),
                        p.netAmount() == null ? null : ClientGeniusPay.enFrancsEntiers(p.netAmount()),
                        p.paymentProvider() != null ? p.paymentProvider() : p.paymentMethod()));
    }

    @Override
    public ResultatRemboursement rembourser(RequeteRemboursement requete) {
        throw new UnsupportedOperationException(
                "Le remboursement GeniusPay est hors périmètre (§12) : l'état REMBOURSEE existe pour "
                + "recevoir un webhook de remboursement, aucun déclenchement n'est implémenté.");
    }

    /**
     * Corps de l'initiation.
     *
     * <p><b>Ni nom ni téléphone du patient ne sont envoyés</b>, bien que le contrat les accepte. Deux
     * raisons qui vont dans le même sens : ce sont des données personnelles qui quitteraient le
     * service sans nécessité (la page de checkout demande elle-même son numéro au payeur), et surtout
     * le corps du webhook est archivé <b>intégralement</b> pour permettre de rejouer une vérification
     * de signature lors d'un litige. Ne rien envoyer, c'est garantir que rien de personnel ne peut
     * revenir dans cette archive — on ferme le problème à la source plutôt que de le rattraper par un
     * masquage qui, lui, casserait l'empreinte du corps.</p>
     *
     * <p>{@code description} est un libellé <b>générique et constant</b> : aucun acte, aucune
     * spécialité, aucun service hospitalier ne sort du service (interdiction n°7).</p>
     *
     * <p>{@code metadata.order_id} n'est <b>jamais</b> omis : c'est le seul lien qui permette de
     * rattacher un webhook à une transaction dont nous n'aurions pas la référence prestataire (§7.4.b).</p>
     */
    private Map<String, Object> corpsInitiation(RequetePaiement requete) {
        Map<String, Object> metadata = new LinkedHashMap<>();
        metadata.put("order_id", requete.referenceInterne());
        metadata.put("structure_id", requete.etablissementRef());

        Map<String, Object> corps = new LinkedHashMap<>();
        corps.put("amount", requete.montant());
        corps.put("currency", requete.devise());
        corps.put("description", libelleGenerique);
        // `payment_method` est volontairement OMIS : son absence est ce qui déclenche la page de
        // checkout où le patient choisit son opérateur. Le renseigner choisirait à sa place.
        Map<String, Object> customer = new LinkedHashMap<>();
        customer.put("country", "CI");
        corps.put("customer", customer);
        if (proprietes.getSuccessUrl() != null && !proprietes.getSuccessUrl().isBlank()) {
            corps.put("success_url", proprietes.getSuccessUrl());
        }
        if (proprietes.getErrorUrl() != null && !proprietes.getErrorUrl().isBlank()) {
            corps.put("error_url", proprietes.getErrorUrl());
        }
        corps.put("metadata", metadata);
        return corps;
    }

    private IdentifiantMarchand marchandDe(String etablissementRef) {
        if (etablissementRef == null || etablissementRef.isBlank()) {
            throw new MarchandIntrouvableException("(aucun établissement fourni)");
        }
        return marchands.findByEtablissementRefAndPspAndActifIsTrue(etablissementRef, PSP)
                .orElseThrow(() -> new MarchandIntrouvableException(etablissementRef));
    }

    /**
     * Échéance <b>telle que renvoyée par le prestataire</b>. Une échéance illisible vaut {@code null}
     * plutôt qu'une valeur calculée : sans elle on ne réutilise simplement pas le lien, ce qui coûte
     * un checkout de plus ; avec une échéance inventée on tiendrait pour ouvert un lien déjà mort.
     */
    private static Instant echeance(String expiresAt) {
        if (expiresAt == null || expiresAt.isBlank()) {
            return null;
        }
        try {
            return OffsetDateTime.parse(expiresAt).toInstant();
        } catch (RuntimeException e) {
            try {
                return Instant.parse(expiresAt);
            } catch (RuntimeException ignore) {
                return null;
            }
        }
    }
}
