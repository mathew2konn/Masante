package ci.masante.payment.service;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import jakarta.annotation.PostConstruct;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.data.redis.core.StringRedisTemplate;
import org.springframework.stereotype.Service;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.time.Duration;
import java.time.Instant;
import java.util.Base64;
import java.util.HashSet;
import java.util.Set;

/**
 * Vérifie le <b>principal signé par la passerelle authentifiée</b> (CDC_10, P5.3b-1). Corrige la faille
 * « rôle/identité déclarés par le client » : l'identité et les rôles proviennent d'un jeton dont le
 * service <b>vérifie la signature</b>, jamais d'un en-tête nu.
 *
 * <p>En-têtes : {@code X-Principal} = base64({@code {sub,roles,iat,exp,method,path,nonce}}) et
 * {@code X-Principal-Sig} = base64(HMAC-SHA256(octets bruts de X-Principal, secret)). Contrôles :
 * signature (temps constant), fraîcheur ({@code exp}), <b>liaison à la requête</b> ({@code method}/{@code
 * path}), <b>anti-rejeu</b> ({@code nonce} à usage unique en Redis). Secret <b>distinct</b> de celui des
 * webhooks P5.4a (pas de confusion inter-protocoles). Le service paiement n'est <b>pas joignable de
 * l'extérieur</b> : quiconque a le secret ne peut de toute façon forger que derrière la passerelle.
 * « Prêt à activer » : JWT Keycloak RS256.</p>
 */
@Service
public class ServicePrincipal {

    private static final Logger log = LoggerFactory.getLogger(ServicePrincipal.class);
    private static final Duration FRAICHEUR_MAX = Duration.ofMinutes(5);
    private static final String PREFIXE_NONCE = "principal:nonce:";

    private final ObjectMapper json;
    private final StringRedisTemplate redis;
    private final String secretB64;
    private final boolean exigerCles;
    private final SecureRandom alea = new SecureRandom();
    private byte[] secret;

    public ServicePrincipal(ObjectMapper json, StringRedisTemplate redis,
                            @Value("${MASANTE_PAYMENT_PRINCIPAL_SECRET:}") String secretB64,
                            @Value("${masante.payment.securite.exiger-cles:false}") boolean exigerCles) {
        this.json = json;
        this.redis = redis;
        this.secretB64 = secretB64;
        this.exigerCles = exigerCles;
    }

    @PostConstruct
    void init() {
        if (secretB64 != null && !secretB64.isBlank()) {
            this.secret = Base64.getDecoder().decode(secretB64.trim());
            return;
        }
        if (exigerCles) {
            throw new IllegalStateException("Profil durci : MASANTE_PAYMENT_PRINCIPAL_SECRET absente — démarrage refusé (CDC_10).");
        }
        this.secret = new byte[32];
        alea.nextBytes(secret);
        log.warn("MASANTE_PAYMENT_PRINCIPAL_SECRET absente : secret de DÉV généré en mémoire. NE PAS utiliser en prod.");
    }

    /** Vérifie et renvoie le principal authentifié, ou lève {@link PrincipalInvalideException} (401). */
    public PrincipalAuthentifie verifier(String xPrincipal, String xSig, String methode, String chemin) {
        if (xPrincipal == null || xSig == null) {
            throw new PrincipalInvalideException();
        }
        // 1) Signature sur les OCTETS BRUTS de X-Principal (pas de re-sérialisation), temps constant.
        byte[] attendu = hmac(xPrincipal.getBytes(StandardCharsets.UTF_8));
        byte[] fourni;
        try {
            fourni = Base64.getDecoder().decode(xSig);
        } catch (IllegalArgumentException e) {
            throw new PrincipalInvalideException();
        }
        if (!MessageDigest.isEqual(attendu, fourni)) {
            throw new PrincipalInvalideException();
        }
        // 2) Décodage + claims.
        JsonNode p;
        try {
            p = json.readTree(Base64.getDecoder().decode(xPrincipal));
        } catch (Exception e) {
            throw new PrincipalInvalideException();
        }
        // 3) Liaison à la requête.
        if (!chemin.equals(texte(p, "path")) || !methode.equalsIgnoreCase(texte(p, "method"))) {
            throw new PrincipalInvalideException();
        }
        // 4) Fraîcheur.
        Instant exp = instant(p, "exp");
        Instant iat = instant(p, "iat");
        Instant maintenant = Instant.now();
        if (exp == null || iat == null || maintenant.isAfter(exp)
                || Duration.between(iat, maintenant).abs().compareTo(FRAICHEUR_MAX) > 0) {
            throw new PrincipalInvalideException();
        }
        // 5) Anti-rejeu : nonce à usage unique.
        String nonce = texte(p, "nonce");
        if (nonce == null || nonce.isBlank()) {
            throw new PrincipalInvalideException();
        }
        Boolean neuf = redis.opsForValue().setIfAbsent(PREFIXE_NONCE + nonce, "1", FRAICHEUR_MAX);
        if (!Boolean.TRUE.equals(neuf)) {
            throw new PrincipalInvalideException();
        }
        String sub = texte(p, "sub");
        if (sub == null || sub.isBlank()) {
            throw new PrincipalInvalideException();
        }
        Set<String> roles = new HashSet<>();
        if (p.has("roles") && p.get("roles").isArray()) {
            p.get("roles").forEach(n -> roles.add(n.asText()));
        }
        return new PrincipalAuthentifie(sub, roles);
    }

    /** Exige un rôle ; lève {@link RoleInsuffisantException} (403) sinon. */
    public void exigerRole(PrincipalAuthentifie principal, String role) {
        if (principal == null || !principal.roles().contains(role)) {
            throw new RoleInsuffisantException();
        }
    }

    private byte[] hmac(byte[] donnees) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret, "HmacSHA256"));
            return mac.doFinal(donnees);
        } catch (Exception e) {
            throw new IllegalStateException("HMAC principal impossible", e);
        }
    }

    private static String texte(JsonNode n, String champ) {
        return n.hasNonNull(champ) ? n.get(champ).asText() : null;
    }

    private static Instant instant(JsonNode n, String champ) {
        if (!n.hasNonNull(champ)) {
            return null;
        }
        try {
            return Instant.ofEpochSecond(n.get(champ).asLong());
        } catch (Exception e) {
            return null;
        }
    }

    public record PrincipalAuthentifie(String sub, Set<String> roles) {
    }
}
