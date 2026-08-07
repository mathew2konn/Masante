package ci.masante.payment.domain.reversement;

import ci.masante.payment.domain.model.TypeDestination;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** Validation de format des destinations (P5.5b-1) : MSISDN CI + IBAN CI mod-97. Pur (G3). */
class ReglesDestinationTest {

    private static void valider(TypeDestination type, String ref) {
        ReglesDestination.valider(type, ReglesDestination.normaliser(type, ref));
    }

    @Test
    @DisplayName("MSISDN CI valides : Orange 07, MTN 05, Moov 01, avec/sans +225 et séparateurs")
    void msisdnValides() {
        assertThatCode(() -> valider(TypeDestination.MOBILE_MONEY, "0709010203")).doesNotThrowAnyException();
        assertThatCode(() -> valider(TypeDestination.MOBILE_MONEY, "+225 05 09 01 02 03")).doesNotThrowAnyException();
        assertThatCode(() -> valider(TypeDestination.MOBILE_MONEY, "01-09-01-02-03")).doesNotThrowAnyException();
        assertThat(ReglesDestination.operateur(TypeDestination.MOBILE_MONEY, "0709010203")).isEqualTo("Orange Money");
        assertThat(ReglesDestination.operateur(TypeDestination.MOBILE_MONEY, "0509010203")).isEqualTo("MTN MoMo");
    }

    @Test
    @DisplayName("MSISDN invalides : préfixe inconnu, mauvaise longueur")
    void msisdnInvalides() {
        assertThatThrownBy(() -> valider(TypeDestination.MOBILE_MONEY, "0209010203"))
                .isInstanceOf(DestinationInvalideException.class);
        assertThatThrownBy(() -> valider(TypeDestination.MOBILE_MONEY, "070901"))
                .isInstanceOf(DestinationInvalideException.class);
    }

    @Test
    @DisplayName("IBAN CI valide (mod-97) accepté ; altéré rejeté")
    void ibanCi() {
        // IBAN CI de test avec contrôle mod-97 correct (BBAN 24 caractères).
        String valide = ibanCiValide();
        assertThatCode(() -> valider(TypeDestination.VIREMENT_BANCAIRE, valide)).doesNotThrowAnyException();
        // Altération d'un chiffre → mod-97 échoue.
        String altere = valide.substring(0, valide.length() - 1)
                + (valide.charAt(valide.length() - 1) == '0' ? '1' : '0');
        assertThatThrownBy(() -> valider(TypeDestination.VIREMENT_BANCAIRE, altere))
                .isInstanceOf(DestinationInvalideException.class);
    }

    @Test
    @DisplayName("IBAN de mauvaise longueur rejeté")
    void ibanLongueur() {
        assertThatThrownBy(() -> valider(TypeDestination.VIREMENT_BANCAIRE, "CI93CI00"))
                .isInstanceOf(DestinationInvalideException.class);
    }

    /** Construit un IBAN CI (28 car.) valide : BBAN fixe, clé calculée pour que mod-97 == 1. */
    private static String ibanCiValide() {
        // Cherche les 2 chiffres de contrôle kk tels que mod-97 == 1 pour un BBAN fixe.
        String pays = "CI";
        String corps24 = "12345678901234567890ABCD"; // 24 caractères BBAN arbitraires (lettres → chiffres)
        for (int kk = 2; kk < 100; kk++) {
            String iban = pays + String.format("%02d", kk) + corps24;
            String rearr = iban.substring(4) + iban.substring(0, 4);
            StringBuilder num = new StringBuilder();
            for (char c : rearr.toCharArray()) {
                num.append(Character.isDigit(c) ? String.valueOf(c) : Character.getNumericValue(c));
            }
            if (new java.math.BigInteger(num.toString()).mod(java.math.BigInteger.valueOf(97)).intValue() == 1) {
                return iban;
            }
        }
        throw new IllegalStateException("Aucune clé IBAN trouvée pour le test");
    }
}
