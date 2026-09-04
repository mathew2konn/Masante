package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.MarchandIntrouvableException;
import ci.masante.payment.domain.model.IdentifiantMarchand;
import ci.masante.payment.repository.IdentifiantMarchandRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.security.SecureRandom;
import java.util.Base64;
import java.util.UUID;

/**
 * Enregistrement et rotation des identifiants marchands (§6.2).
 *
 * <p><b>Aucun secret ne traverse cette classe en clair plus longtemps que nécessaire</b> : il arrive
 * en paramètre, il est chiffré, il est écrit. Rien n'est journalisé, rien n'est renvoyé.</p>
 */
@Service
public class ServiceMarchandGeniusPay {

    private static final SecureRandom ALEA = new SecureRandom();

    private final IdentifiantMarchandRepository marchands;
    private final GestionnaireSecretsMarchand secrets;
    private final ServiceAudit audit;
    private final ProprietesGeniusPay proprietes;

    public ServiceMarchandGeniusPay(IdentifiantMarchandRepository marchands,
                                    GestionnaireSecretsMarchand secrets,
                                    ServiceAudit audit,
                                    ProprietesGeniusPay proprietes) {
        this.marchands = marchands;
        this.secrets = secrets;
        this.audit = audit;
        this.proprietes = proprietes;
    }

    @Transactional
    public IdentifiantMarchand enregistrer(String etablissementRef, String clePublique, String cleSecrete) {
        // L'identifiant est tiré AVANT le chiffrement : il entre dans l'AAD, qui lie le cryptogramme
        // au couple (établissement, enregistrement). Sans lui, un blob serait transplantable.
        UUID id = UUID.randomUUID();
        var chiffre = secrets.chiffrer(cleSecrete, etablissementRef, id);

        IdentifiantMarchand marchand = marchands.save(new IdentifiantMarchand(
                id, etablissementRef, AdaptateurGeniusPay.PSP, slug(), clePublique,
                chiffre.cipher(), chiffre.nonce(), chiffre.cleVersion(), proprietes.getEnvironnement()));

        // L'audit nomme l'établissement et l'action, jamais la clé ni le slug.
        audit.enregistrer("GeniusPayMerchantRegistered", "merchant", etablissementRef,
                java.util.Map.of("psp", AdaptateurGeniusPay.PSP));
        return marchand;
    }

    /**
     * L'établissement peut-il encaisser en ligne AUJOURD'HUI ? (B4, S7, ADR-056).
     *
     * <p>Deux conditions, les deux réelles : un compte marchand actif chez ce prestataire, ET un
     * secret webhook déposé — sans lui, un paiement resterait bloqué en attente de confirmation, le
     * webhook du prestataire étant rejeté à la vérification de signature (§8.4). « configuré » veut
     * dire « capable d'aller jusqu'au bout », pas seulement « une ligne existe ».</p>
     *
     * <p><b>Ne renvoie JAMAIS les clés</b> — cette méthode répond à une question booléenne, elle ne
     * lit les secrets d'aucune façon (contrairement à {@code GestionnaireSecretsMarchand}, jamais
     * appelé ici). C'est ce qui rend l'endpoint {@code GET /marchands/{ref}} sûr à exposer à Laravel :
     * il n'y a rien à fuir dans la réponse par construction, pas seulement par discipline d'écriture.</p>
     */
    @Transactional(readOnly = true)
    public boolean estConfigure(String etablissementRef) {
        return marchands.findByEtablissementRefAndPspAndActifIsTrue(etablissementRef, AdaptateurGeniusPay.PSP)
                .map(IdentifiantMarchand::aUnSecretWebhook)
                .orElse(false);
    }

    @Transactional
    public void deposerSecretWebhook(String etablissementRef, String secretWebhook) {
        IdentifiantMarchand marchand = marchands
                .findByEtablissementRefAndPspAndActifIsTrue(etablissementRef, AdaptateurGeniusPay.PSP)
                .orElseThrow(() -> new MarchandIntrouvableException(etablissementRef));
        var chiffre = secrets.chiffrer(secretWebhook, etablissementRef, marchand.getId());
        marchand.poserSecretWebhook(chiffre.cipher(), chiffre.nonce());
        marchands.save(marchand);
        audit.enregistrer("GeniusPayWebhookSecretStored", "merchant", etablissementRef,
                java.util.Map.of("psp", AdaptateurGeniusPay.PSP));
    }

    /**
     * Identifiant de rappel : 24 caractères d'aléa cryptographique en base64 URL.
     *
     * <p>Il ne dérive de <b>rien</b> — ni de l'établissement, ni de la date, ni d'un compteur. Un slug
     * dérivé serait devinable, et deviner un slug, c'est apprendre qu'un établissement est partenaire.
     * Il n'ouvre d'ailleurs aucun droit : il ne fait que désigner quel secret servira à vérifier une
     * signature qui, elle, décide.</p>
     */
    private static String slug() {
        byte[] brut = new byte[18];
        ALEA.nextBytes(brut);
        return Base64.getUrlEncoder().withoutPadding().encodeToString(brut);
    }
}
