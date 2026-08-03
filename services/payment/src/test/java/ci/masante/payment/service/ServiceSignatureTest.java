package ci.masante.payment.service;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.Optional;

import static org.assertj.core.api.Assertions.assertThat;

/** Signature RSA-SHA256 « prête à activer » (CDC_06 §7.4). Test pur (JDK), exécuté au build. */
class ServiceSignatureTest {

    private static final String HASH = "a".repeat(64);

    private static ServiceSignature service(boolean actif) throws Exception {
        ServiceSignature s = new ServiceSignature(actif);
        s.init(); // @PostConstruct manuel en test unitaire
        return s;
    }

    @Test
    @DisplayName("Active : un hash est signé puis vérifié avec la clé publique stockée")
    void signeEtVerifie() throws Exception {
        ServiceSignature s = service(true);

        Optional<SceauSignature> sceau = s.signer(HASH);
        assertThat(sceau).isPresent();
        assertThat(sceau.get().algorithme()).isEqualTo("SHA256withRSA");
        assertThat(s.verifier(HASH, sceau.get().signature(), sceau.get().cléPublique())).isTrue();
    }

    @Test
    @DisplayName("Une altération du hash invalide la signature")
    void detecteAlteration() throws Exception {
        ServiceSignature s = service(true);
        SceauSignature sceau = s.signer(HASH).orElseThrow();

        assertThat(s.verifier("b".repeat(64), sceau.signature(), sceau.cléPublique())).isFalse();
    }

    @Test
    @DisplayName("Désactivée : aucun sceau n'est produit")
    void desactivee() throws Exception {
        assertThat(service(false).signer(HASH)).isEmpty();
    }
}
