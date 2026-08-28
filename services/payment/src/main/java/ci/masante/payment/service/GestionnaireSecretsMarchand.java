package ci.masante.payment.service;

import ci.masante.payment.domain.model.IdentifiantMarchand;
import org.springframework.stereotype.Service;

import java.util.UUID;

/**
 * Garde des secrets marchands (§6.2). Chiffrement <b>enveloppe</b> AES-256-GCM, clé de données liée à
 * l'établissement et à l'enregistrement par l'AAD.
 *
 * <p><b>Aucune cryptographie n'est réécrite ici.</b> Le mécanisme existe et est éprouvé depuis
 * P5.5b-1 ({@link ServiceChiffrementDestination}) : nonce aléatoire par chiffrement, AAD à préfixe de
 * longueur, version de clé stockée pour la rotation, matériel par variable d'environnement avec refus
 * de démarrage en profil durci. En recopier les quatre-vingts lignes aurait produit une seconde
 * implémentation de la même primitive, et deux implémentations d'une même primitive finissent par
 * diverger — ici, sur des secrets de tiers. Cette classe est la <b>façade</b> que le prompt appelle
 * {@code GestionnaireSecrets} : le jour où le chiffrement s'adosse à un KMS, aucun appelant ne bouge.</p>
 *
 * <p><b>L'AAD n'est pas décorative.</b> Elle lie le cryptogramme au couple (établissement,
 * enregistrement) : recopier le blob d'un marchand vers la ligne d'un autre <b>échoue au
 * déchiffrement</b> au lieu d'attribuer silencieusement la clé d'un partenaire à un autre. Le motif
 * vient de la PKI (ADR-032), où il empêchait d'attribuer la clé privée d'un praticien à un confrère.</p>
 *
 * <p><b>Limite dite, non déguisée.</b> Le matériel de chiffrement est celui de
 * {@code MASANTE_PAYMENT_DEST_KEY} : une seule clé maître protège aujourd'hui deux classes d'actifs
 * (références de destination et secrets marchands). Les séparer demanderait une seconde variable
 * d'environnement et une seconde rotation ; tant que ce n'est pas fait, la compromission de l'une
 * expose l'autre.</p>
 */
@Service
public class GestionnaireSecretsMarchand {

    private final ServiceChiffrementDestination chiffrement;

    public GestionnaireSecretsMarchand(ServiceChiffrementDestination chiffrement) {
        this.chiffrement = chiffrement;
    }

    public ServiceChiffrementDestination.ResultatChiffrement chiffrer(String secretClair,
                                                                     String etablissementRef,
                                                                     UUID identifiantMarchandId) {
        return chiffrement.chiffrer(secretClair, etablissementRef, identifiantMarchandId);
    }

    public short versionCourante() {
        return chiffrement.versionCle();
    }

    /**
     * Déchiffre la clé d'API du marchand. Le retour est une {@code String} de courte durée de vie :
     * elle part directement dans un en-tête HTTP et n'est ni journalisée, ni mise en cache, ni
     * renvoyée par une API.
     */
    public String cleSecrete(IdentifiantMarchand marchand) {
        return chiffrement.dechiffrer(marchand.getCleSecreteChiffree(), marchand.getCleSecreteNonce(),
                marchand.getCleVersion(), marchand.getEtablissementRef(), marchand.getId());
    }

    /**
     * Déchiffre le secret webhook. C'est le <b>seul</b> chemin par lequel une signature entrante peut
     * être vérifiée : sans secret enregistré, aucun webhook de ce marchand n'est acceptable.
     */
    public String secretWebhook(IdentifiantMarchand marchand) {
        if (!marchand.aUnSecretWebhook()) {
            throw new SecretMarchandAbsentException(marchand.getEtablissementRef());
        }
        return chiffrement.dechiffrer(marchand.getSecretWebhookChiffre(), marchand.getSecretWebhookNonce(),
                marchand.getCleVersion(), marchand.getEtablissementRef(), marchand.getId());
    }
}
