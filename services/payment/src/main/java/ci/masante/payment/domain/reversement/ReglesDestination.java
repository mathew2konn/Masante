package ci.masante.payment.domain.reversement;

import ci.masante.payment.domain.model.TypeDestination;

import java.math.BigInteger;

/**
 * Règles PURES de validation de format d'une destination de reversement (CDC_06 §11). Gratuit ici,
 * bloque l'argent en b-2 sinon. Normalise + valide sans I/O (testable G3).
 *
 * <ul>
 *   <li><b>MOBILE_MONEY</b> : MSISDN Côte d'Ivoire — {@code +225} optionnel + 10 chiffres, préfixe
 *       opérateur {@code 01} (Moov) / {@code 05} (MTN) / {@code 07} (Orange).</li>
 *   <li><b>VIREMENT_BANCAIRE</b> : IBAN CI — 28 caractères {@code CIkk…}, contrôle <b>mod-97</b>
 *       (ISO 13616 / ISO 7064).</li>
 * </ul>
 */
public final class ReglesDestination {

    private ReglesDestination() {
    }

    /** Normalise la référence (retire espaces/tirets, majuscules pour l'IBAN). */
    public static String normaliser(TypeDestination type, String ref) {
        if (ref == null || ref.isBlank()) {
            throw new DestinationInvalideException("Référence de destination vide.");
        }
        String compact = ref.replaceAll("[\\s-]", "");
        return type == TypeDestination.VIREMENT_BANCAIRE ? compact.toUpperCase() : compact;
    }

    /** Valide la référence NORMALISÉE ; lève {@link DestinationInvalideException} sinon. */
    public static void valider(TypeDestination type, String refNormalisee) {
        switch (type) {
            case MOBILE_MONEY -> validerMsisdnCi(refNormalisee);
            case VIREMENT_BANCAIRE -> validerIbanCi(refNormalisee);
        }
    }

    /** Libellé non sensible : opérateur/banque, aucun chiffre du compte (anti-réidentification). */
    public static String operateur(TypeDestination type, String refNormalisee) {
        if (type == TypeDestination.VIREMENT_BANCAIRE) {
            return "Virement bancaire";
        }
        String national = refNormalisee.startsWith("+225") ? refNormalisee.substring(4) : refNormalisee;
        String prefixe = national.length() >= 2 ? national.substring(0, 2) : "";
        return switch (prefixe) {
            case "01" -> "Moov Money";
            case "05" -> "MTN MoMo";
            case "07" -> "Orange Money";
            default -> "Mobile Money";
        };
    }

    private static void validerMsisdnCi(String ref) {
        String national = ref.startsWith("+225") ? ref.substring(4) : ref;
        if (!national.matches("\\d{10}")) {
            throw new DestinationInvalideException("MSISDN invalide : 10 chiffres attendus (préfixe +225 optionnel).");
        }
        String prefixe = national.substring(0, 2);
        if (!prefixe.equals("01") && !prefixe.equals("05") && !prefixe.equals("07")) {
            throw new DestinationInvalideException("Préfixe opérateur inconnu : " + prefixe + " (attendu 01/05/07).");
        }
    }

    private static void validerIbanCi(String iban) {
        if (!iban.matches("CI\\d{2}[0-9A-Z]{24}")) {
            throw new DestinationInvalideException("IBAN CI invalide : 28 caractères 'CIkk…' attendus.");
        }
        // Mod-97 (ISO 7064) : déplacer les 4 premiers caractères en fin, convertir lettres → nombres,
        // le reste modulo 97 doit valoir 1.
        String rearrange = iban.substring(4) + iban.substring(0, 4);
        StringBuilder numerique = new StringBuilder(rearrange.length() * 2);
        for (char c : rearrange.toCharArray()) {
            if (Character.isDigit(c)) {
                numerique.append(c);
            } else {
                numerique.append(Character.getNumericValue(c)); // A=10 … Z=35
            }
        }
        if (!new BigInteger(numerique.toString()).mod(BigInteger.valueOf(97)).equals(BigInteger.ONE)) {
            throw new DestinationInvalideException("IBAN CI : contrôle mod-97 échoué.");
        }
    }
}
