package ci.masante.payment.service;

import jakarta.annotation.PostConstruct;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;

import javax.crypto.Cipher;
import javax.crypto.Mac;
import javax.crypto.spec.GCMParameterSpec;
import javax.crypto.spec.SecretKeySpec;
import java.nio.ByteBuffer;
import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import java.util.Base64;
import java.util.HexFormat;
import java.util.UUID;

/**
 * Chiffrement des références de destination (MSISDN/IBAN) et empreinte de comparaison (CDC_06 §11,
 * CDC_10). JAMAIS de donnée en clair en base ni en log.
 *
 * <ul>
 *   <li><b>Confidentialité</b> : AES-256-GCM. Nonce 12 o aléatoire par chiffrement (stocké). <b>AAD</b> =
 *       {@code longueur(etablissement_ref)‖etablissement_ref‖id} → un blob ne peut être transplanté d'un
 *       établissement/enregistrement à l'autre. {@code cle_version} stockée (rotation).</li>
 *   <li><b>Comparaison sans déchiffrement</b> : {@code empreinte} = <b>HMAC-SHA256 poivré</b> (≠ SHA nu :
 *       l'espace MSISDN ~10⁸ serait sinon brute-forçable). {@code empreinte_version} stockée.</li>
 * </ul>
 *
 * <p><b>« Prêt à activer »</b> : clé/poivre par variable d'environnement, jamais dans le code ni en base.
 * En profil durci ({@code masante.payment.securite.exiger-cles=true}, prod) l'absence de clé/poivre
 * <b>empêche le démarrage</b> (pas un simple avertissement). En dév, une clé éphémère est générée + log.warn.
 * L'adossement à un KMS/HSM est l'étape d'activation.</p>
 */
@Service
public class ServiceChiffrementDestination {

    private static final Logger log = LoggerFactory.getLogger(ServiceChiffrementDestination.class);
    private static final String ALGO = "AES/GCM/NoPadding";
    private static final int TAILLE_NONCE = 12;      // recommandation GCM
    private static final int TAILLE_TAG_BITS = 128;
    private static final short VERSION_CLE = 1;
    private static final short VERSION_EMPREINTE = 1;

    private final SecureRandom alea = new SecureRandom();
    private final String cleB64;
    private final String poivreB64;
    private final boolean exigerCles;

    private SecretKeySpec cleAes;
    private byte[] poivre;

    public ServiceChiffrementDestination(
            @Value("${MASANTE_PAYMENT_DEST_KEY:}") String cleB64,
            @Value("${MASANTE_PAYMENT_DEST_PEPPER:}") String poivreB64,
            @Value("${masante.payment.securite.exiger-cles:false}") boolean exigerCles) {
        this.cleB64 = cleB64;
        this.poivreB64 = poivreB64;
        this.exigerCles = exigerCles;
    }

    @PostConstruct
    void init() {
        this.cleAes = new SecretKeySpec(materiel(cleB64, "MASANTE_PAYMENT_DEST_KEY", 32), "AES");
        this.poivre = materiel(poivreB64, "MASANTE_PAYMENT_DEST_PEPPER", 32);
    }

    /** Charge un matériel base64 ; en durci l'absence est fatale, en dév on génère (éphémère) + avertit. */
    private byte[] materiel(String b64, String nom, int taille) {
        if (b64 != null && !b64.isBlank()) {
            byte[] brut = Base64.getDecoder().decode(b64.trim());
            if (brut.length != taille) {
                throw new IllegalStateException(nom + " doit faire " + taille + " octets (base64).");
            }
            return brut;
        }
        if (exigerCles) {
            throw new IllegalStateException("Profil durci : " + nom + " absente — démarrage refusé (CDC_10).");
        }
        byte[] genere = new byte[taille];
        alea.nextBytes(genere);
        log.warn("{} absente : matériel de DÉV généré en mémoire (substitut KMS). NE PAS utiliser en prod.", nom);
        return genere;
    }

    public short versionCle() {
        return VERSION_CLE;
    }

    public short versionEmpreinte() {
        return VERSION_EMPREINTE;
    }

    /** Chiffre une référence, liée à (établissement, id) par l'AAD. */
    public ResultatChiffrement chiffrer(String clair, String etablissementRef, UUID id) {
        try {
            byte[] nonce = new byte[TAILLE_NONCE];
            alea.nextBytes(nonce);
            Cipher c = Cipher.getInstance(ALGO);
            c.init(Cipher.ENCRYPT_MODE, cleAes, new GCMParameterSpec(TAILLE_TAG_BITS, nonce));
            c.updateAAD(aad(etablissementRef, id));
            byte[] cipher = c.doFinal(clair.getBytes(StandardCharsets.UTF_8));
            return new ResultatChiffrement(cipher, nonce, VERSION_CLE);
        } catch (Exception e) {
            throw new IllegalStateException("Chiffrement de la destination impossible", e);
        }
    }

    /** Déchiffre (chemin de versement P5.5b-2). L'AAD doit correspondre exactement. */
    public String dechiffrer(byte[] cipher, byte[] nonce, short cleVersion, String etablissementRef, UUID id) {
        if (cleVersion != VERSION_CLE) {
            throw new IllegalStateException("Version de clé inconnue : " + cleVersion);
        }
        try {
            Cipher c = Cipher.getInstance(ALGO);
            c.init(Cipher.DECRYPT_MODE, cleAes, new GCMParameterSpec(TAILLE_TAG_BITS, nonce));
            c.updateAAD(aad(etablissementRef, id));
            return new String(c.doFinal(cipher), StandardCharsets.UTF_8);
        } catch (Exception e) {
            throw new IllegalStateException("Déchiffrement de la destination impossible", e);
        }
    }

    /** Empreinte HMAC-SHA256 poivrée (hex) pour comparaison sans déchiffrement. */
    public String empreinte(String clair) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(poivre, "HmacSHA256"));
            return HexFormat.of().formatHex(mac.doFinal(clair.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception e) {
            throw new IllegalStateException("Calcul d'empreinte impossible", e);
        }
    }

    /** AAD = longueur(etab)‖etab‖id — préfixe de longueur (jamais un séparateur ambigu). */
    private static byte[] aad(String etablissementRef, UUID id) {
        byte[] etab = etablissementRef.getBytes(StandardCharsets.UTF_8);
        return ByteBuffer.allocate(4 + etab.length + 16)
                .putInt(etab.length).put(etab)
                .putLong(id.getMostSignificantBits()).putLong(id.getLeastSignificantBits())
                .array();
    }

    public record ResultatChiffrement(byte[] cipher, byte[] nonce, short cleVersion) {
    }
}
