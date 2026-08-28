package ci.masante.payment.service;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import jakarta.annotation.PostConstruct;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Émet un <b>principal signé</b> pour les appels SORTANTS du service (lot 6, canal interne).
 *
 * <p>Miroir exact de ce que {@link ServicePrincipal#verifier} accepte en entrée — mêmes claims,
 * même base64, même HMAC sur les <b>octets bruts</b> de {@code X-Principal}. Le seul secret utilisé
 * est {@code MASANTE_PAYMENT_PRINCIPAL_SECRET}, celui-là même que la vérification entrante emploie :
 * un second secret ferait diverger les deux sens du canal sans qu'aucun test unitaire ne le voie.</p>
 *
 * <p><b>Aucun secret n'est jamais généré ici.</b> {@link ServicePrincipal} peut, en développement,
 * se replier sur un secret aléatoire en mémoire — c'est acceptable pour <i>vérifier</i> (rien ne
 * passera, et c'est le comportement sûr), jamais pour <i>signer</i> : on émettrait des appels que
 * personne au monde ne peut vérifier, et l'échec ne se verrait qu'en face. Secret absent →
 * {@link #signer} lève, la notification part en ECHOUEE avec un motif lisible, le relais réessaiera.
 * Le service démarre néanmoins : un canal optionnel ne doit pas empêcher 23 contrôleurs de servir.</p>
 *
 * <p>Aucun {@code toString()} n'expose le secret, et il n'est jamais journalisé.</p>
 */
@Service
public class SigneurPrincipalSortant {

    private static final Logger log = LoggerFactory.getLogger(SigneurPrincipalSortant.class);

    /** Durée de validité du principal émis — même valeur que les clients Laravel et Next. */
    private static final long VALIDITE_SECONDES = 120;

    private final ObjectMapper json;
    private final String secretB64;
    private final String sub;
    private final List<String> roles;
    private byte[] secret;

    public SigneurPrincipalSortant(
            ObjectMapper json,
            @Value("${MASANTE_PAYMENT_PRINCIPAL_SECRET:}") String secretB64,
            @Value("${masante.payment.canal-interne.principal-sub:paiement-service}") String sub,
            @Value("${masante.payment.canal-interne.principal-roles:SYSTEME}") String roles) {
        this.json = json;
        this.secretB64 = secretB64;
        this.sub = sub;
        this.roles = List.of(roles.split("\\s*,\\s*"));
    }

    @PostConstruct
    void init() {
        if (secretB64 == null || secretB64.isBlank()) {
            log.warn("MASANTE_PAYMENT_PRINCIPAL_SECRET absente : aucun appel sortant signé ne pourra être émis.");
            return;
        }
        this.secret = Base64.getDecoder().decode(secretB64.trim());
    }

    /** Vrai si le service est en mesure de signer (secret présent). */
    public boolean peutSigner() {
        return secret != null;
    }

    /**
     * Produit les en-têtes {@code X-Principal} / {@code X-Principal-Sig} liés à cette requête précise.
     *
     * @param methode verbe HTTP, tel qu'il sera émis (lié dans la signature)
     * @param chemin  chemin SANS chaîne de requête, tel que le vérificateur d'en face le lira
     * @throws IllegalStateException si aucun secret partagé n'est configuré
     */
    public Map<String, String> signer(String methode, String chemin) {
        if (secret == null) {
            throw new IllegalStateException(
                    "Aucun secret partagé configuré (MASANTE_PAYMENT_PRINCIPAL_SECRET) : "
                    + "impossible de signer un appel sortant.");
        }

        long maintenant = Instant.now().getEpochSecond();
        // LinkedHashMap : ordre stable et lisible. L'ordre n'a AUCUNE incidence sur la validité — la
        // signature porte sur les octets produits, et le vérificateur redécode le JSON pour lire les
        // claims. C'est précisément ce qui rend le canal insensible aux re-sérialisations.
        Map<String, Object> claims = new LinkedHashMap<>();
        claims.put("sub", sub);
        claims.put("roles", roles);
        claims.put("iat", maintenant);
        claims.put("exp", maintenant + VALIDITE_SECONDES);
        claims.put("method", methode);
        claims.put("path", chemin);
        claims.put("nonce", UUID.randomUUID().toString());

        String principalB64;
        try {
            principalB64 = Base64.getEncoder().encodeToString(
                    json.writeValueAsString(claims).getBytes(StandardCharsets.UTF_8));
        } catch (JsonProcessingException e) {
            throw new IllegalStateException("Sérialisation du principal impossible", e);
        }

        String signature = Base64.getEncoder().encodeToString(
                hmac(principalB64.getBytes(StandardCharsets.UTF_8)));

        return Map.of("X-Principal", principalB64, "X-Principal-Sig", signature);
    }

    private byte[] hmac(byte[] donnees) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret, "HmacSHA256"));
            return mac.doFinal(donnees);
        } catch (Exception e) {
            throw new IllegalStateException("HMAC du principal sortant impossible", e);
        }
    }

    /** Ne révèle jamais le secret. */
    @Override
    public String toString() {
        return "SigneurPrincipalSortant{sub=" + sub + ", peutSigner=" + peutSigner() + "}";
    }
}
