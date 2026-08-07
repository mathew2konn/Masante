package ci.masante.payment.web;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Détecteur de PAN en clair (défense PCI §9). Test PUR (aucune I/O) → exécuté pendant le build.
 * Prouve : un numéro de carte Luhn-valide (brut, formaté, ou noyé dans un JSON) est repéré ; les données
 * métier normales (montants, téléphones, UUID) ne déclenchent pas de faux positif.
 */
class DetecteurPanTest {

    @Test
    @DisplayName("PAN Luhn-valide 16 chiffres (brut) détecté")
    void panBrut() {
        assertThat(DetecteurPan.contientPan("4242424242424242")).isTrue();
        assertThat(DetecteurPan.contientPan("4111111111111111")).isTrue();
    }

    @Test
    @DisplayName("PAN formaté avec espaces ou tirets détecté")
    void panFormate() {
        assertThat(DetecteurPan.contientPan("4242 4242 4242 4242")).isTrue();
        assertThat(DetecteurPan.contientPan("4242-4242-4242-4242")).isTrue();
    }

    @Test
    @DisplayName("PAN noyé dans un corps JSON détecté (ex. glissé dans un champ)")
    void panDansJson() {
        assertThat(DetecteurPan.contientPan("{\"referenceClient\":\"4242424242424242\",\"montant\":6000}"))
                .isTrue();
    }

    @Test
    @DisplayName("Numéro de 16 chiffres NON Luhn-valide : ignoré")
    void nonLuhn() {
        assertThat(DetecteurPan.contientPan("4242424242424241")).isFalse();
    }

    @Test
    @DisplayName("Données métier normales : aucun faux positif")
    void pasDeFauxPositif() {
        assertThat(DetecteurPan.contientPan("{\"montant\":20000,\"telephone\":\"0102030405\"}")).isFalse();
        assertThat(DetecteurPan.contientPan("tok_test_frictionless")).isFalse();
        assertThat(DetecteurPan.contientPan("550e8400-e29b-41d4-a716-446655440000")).isFalse();
        assertThat(DetecteurPan.contientPan("")).isFalse();
        assertThat(DetecteurPan.contientPan(null)).isFalse();
    }
}
