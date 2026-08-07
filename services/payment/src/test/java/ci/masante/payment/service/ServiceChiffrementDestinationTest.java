package ci.masante.payment.service;

import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.Arrays;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/**
 * Chiffrement des destinations (P5.5b-1). Clés de DÉV générées en mémoire (profil non durci). Prouve :
 * nonce aléatoire (2 chiffrements du même clair diffèrent), round-trip, liaison AAD (établissement/id),
 * empreinte stable et non triviale.
 */
class ServiceChiffrementDestinationTest {

    private ServiceChiffrementDestination svc;

    @BeforeEach
    void init() {
        svc = new ServiceChiffrementDestination("", "", false);
        svc.init(); // @PostConstruct : génère un matériel de dév éphémère
    }

    @Test
    @DisplayName("Nonce aléatoire : le même clair chiffré deux fois donne des ciphertexts différents")
    void nonceAleatoire() {
        UUID id = UUID.randomUUID();
        var a = svc.chiffrer("0709010203", "ETB-1", id);
        var b = svc.chiffrer("0709010203", "ETB-1", id);
        assertThat(a.nonce()).isNotEqualTo(b.nonce());
        assertThat(a.cipher()).isNotEqualTo(b.cipher());
    }

    @Test
    @DisplayName("Round-trip : déchiffrement rend le clair d'origine")
    void roundTrip() {
        UUID id = UUID.randomUUID();
        var c = svc.chiffrer("CI9310010203040506070809", "ETB-1", id);
        assertThat(svc.dechiffrer(c.cipher(), c.nonce(), c.cleVersion(), "ETB-1", id))
                .isEqualTo("CI9310010203040506070809");
    }

    @Test
    @DisplayName("Liaison AAD : déchiffrer avec un autre établissement/id échoue (anti-transplantation)")
    void aadLie() {
        UUID id = UUID.randomUUID();
        var c = svc.chiffrer("0709010203", "ETB-1", id);
        assertThatThrownBy(() -> svc.dechiffrer(c.cipher(), c.nonce(), c.cleVersion(), "ETB-2", id))
                .isInstanceOf(IllegalStateException.class);
        assertThatThrownBy(() -> svc.dechiffrer(c.cipher(), c.nonce(), c.cleVersion(), "ETB-1", UUID.randomUUID()))
                .isInstanceOf(IllegalStateException.class);
    }

    @Test
    @DisplayName("Empreinte : stable, 64 hex, différente du clair")
    void empreinte() {
        String e1 = svc.empreinte("0709010203");
        String e2 = svc.empreinte("0709010203");
        assertThat(e1).isEqualTo(e2).hasSize(64).doesNotContain("0709010203");
        assertThat(svc.empreinte("0509010203")).isNotEqualTo(e1);
    }

    @Test
    @DisplayName("Le ciphertext ne contient pas le clair en octets")
    void cipherOpaque() {
        var c = svc.chiffrer("0709010203", "ETB-1", UUID.randomUUID());
        String cipherStr = new String(c.cipher(), java.nio.charset.StandardCharsets.ISO_8859_1);
        assertThat(cipherStr).doesNotContain("0709010203");
        assertThat(Arrays.equals(c.cipher(), "0709010203".getBytes())).isFalse();
    }
}
