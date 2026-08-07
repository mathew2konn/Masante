package ci.masante.payment.domain.carte.simulated;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.Map;

/**
 * Vérification de signature HMAC-SHA256 sur le CORPS BRUT d'un webhook (§7.3). JDK pur (aucune dépendance).
 * Comparaison en TEMPS CONSTANT (anti timing-attack). En simulation, le secret est une constante de DÉV ;
 * le vrai secret par PSP viendra de la configuration/HSM (« prêt à activer »).
 */
public final class SignatureHmac {

    private SignatureHmac() {
    }

    public static String hex(byte[] corps, String secret) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            byte[] digest = mac.doFinal(corps);
            StringBuilder sb = new StringBuilder(digest.length * 2);
            for (byte b : digest) {
                sb.append(Character.forDigit((b >> 4) & 0xF, 16));
                sb.append(Character.forDigit(b & 0xF, 16));
            }
            return sb.toString();
        } catch (Exception e) {
            throw new IllegalStateException("HMAC-SHA256 indisponible", e);
        }
    }

    public static boolean verifier(byte[] corps, String secret, String signatureRecue) {
        if (signatureRecue == null) {
            return false;
        }
        byte[] attendu = hex(corps, secret).getBytes(StandardCharsets.UTF_8);
        return MessageDigest.isEqual(attendu, signatureRecue.getBytes(StandardCharsets.UTF_8));
    }

    /** Récupère un en-tête de façon insensible à la casse. */
    public static String entete(Map<String, String> entetes, String nom) {
        if (entetes == null) {
            return null;
        }
        for (Map.Entry<String, String> e : entetes.entrySet()) {
            if (e.getKey() != null && e.getKey().equalsIgnoreCase(nom)) {
                return e.getValue();
            }
        }
        return null;
    }
}
