package ci.masante.payment.service;

import jakarta.annotation.PostConstruct;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;

import java.nio.charset.StandardCharsets;
import java.security.KeyFactory;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.PrivateKey;
import java.security.PublicKey;
import java.security.Signature;
import java.security.spec.X509EncodedKeySpec;
import java.util.Base64;
import java.util.Optional;

/**
 * Signature numérique des factures et avoirs (CDC_06 §7.4 / CDC_10 — PKI).
 *
 * <p><b>« Prête à activer »</b> : implémentation RSA-SHA256 fonctionnelle avec le JDK (aucune
 * dépendance). Gouvernée par {@code masante.payment.signature.enabled}. La clé privée est détenue
 * <b>en mémoire</b> (substitut de HSM/KMS en dev) et n'est JAMAIS écrite dans le code ni en base
 * (interdit CDC_00 §4). La clé publique est stockée avec chaque document pour permettre la
 * vérification, y compris après redémarrage. L'adossement à un HSM/KMS et une chaîne X.509 de
 * confiance est l'étape « activation » (ultérieure).</p>
 */
@Service
public class ServiceSignature {

    private static final Logger log = LoggerFactory.getLogger(ServiceSignature.class);
    private static final String ALGO = "SHA256withRSA";

    private final boolean actif;
    private PrivateKey privee;
    private String publiqueB64;

    public ServiceSignature(@Value("${masante.payment.signature.enabled:true}") boolean actif) {
        this.actif = actif;
    }

    @PostConstruct
    void init() throws Exception {
        if (!actif) {
            log.info("Signature des factures désactivée (masante.payment.signature.enabled=false).");
            return;
        }
        KeyPairGenerator gen = KeyPairGenerator.getInstance("RSA");
        gen.initialize(2048);
        KeyPair paire = gen.generateKeyPair();
        this.privee = paire.getPrivate();
        this.publiqueB64 = Base64.getEncoder().encodeToString(paire.getPublic().getEncoded());
        log.warn("Signature ACTIVE avec une clé RSA de DÉV générée en mémoire (substitut HSM). "
                + "Empreinte clé publique : {}…", publiqueB64.substring(0, 24));
    }

    public boolean estActif() {
        return actif;
    }

    /** Signe le hash d'intégrité (hexadécimal). Vide si la signature est désactivée. */
    public Optional<SceauSignature> signer(String hashIntegrite) {
        if (!actif) {
            return Optional.empty();
        }
        try {
            Signature s = Signature.getInstance(ALGO);
            s.initSign(privee);
            s.update(hashIntegrite.getBytes(StandardCharsets.UTF_8));
            String sig = Base64.getEncoder().encodeToString(s.sign());
            return Optional.of(new SceauSignature(sig, publiqueB64, ALGO));
        } catch (Exception e) {
            throw new IllegalStateException("Signature du document impossible", e);
        }
    }

    /** Vérifie une signature avec la clé publique stockée sur le document. */
    public boolean verifier(String hashIntegrite, String signatureB64, String clePubliqueB64) {
        if (signatureB64 == null || clePubliqueB64 == null) {
            return false;
        }
        try {
            PublicKey pub = KeyFactory.getInstance("RSA")
                    .generatePublic(new X509EncodedKeySpec(Base64.getDecoder().decode(clePubliqueB64)));
            Signature s = Signature.getInstance(ALGO);
            s.initVerify(pub);
            s.update(hashIntegrite.getBytes(StandardCharsets.UTF_8));
            return s.verify(Base64.getDecoder().decode(signatureB64));
        } catch (Exception e) {
            return false;
        }
    }
}
