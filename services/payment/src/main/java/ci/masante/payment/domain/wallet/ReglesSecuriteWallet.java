package ci.masante.payment.domain.wallet;

/**
 * Règles de sécurité pures du wallet (CDC_06 §6.4) — <b>frontière</b> : format du PIN, plafonds
 * par opération/jour/mois, seuil d'OTP. Classe pure (sans Spring), testable en unitaire. Ne lit ni
 * n'écrit aucun état : les valeurs (consommations, plafonds) lui sont fournies.
 *
 * <p>Convention : un plafond {@code <= 0} signifie « illimité » (contrôle ignoré).</p>
 */
public final class ReglesSecuriteWallet {

    private ReglesSecuriteWallet() {
    }

    /** PIN : 4 à 6 chiffres. Ne révèle jamais le PIN dans le message. */
    public static void verifierFormatPin(String pin) {
        if (pin == null || !pin.matches("\\d{4,6}")) {
            throw new PinInvalideException("Le PIN doit comporter 4 à 6 chiffres.");
        }
    }

    public static void verifierLimiteOperation(long montant, long plafondOperation) {
        if (plafondOperation > 0 && montant > plafondOperation) {
            throw new LimiteDepasseeException("par opération", montant, plafondOperation);
        }
    }

    public static void verifierLimiteJournaliere(long dejaConsommeJour, long montant, long plafondJour) {
        if (plafondJour > 0 && dejaConsommeJour + montant > plafondJour) {
            throw new LimiteDepasseeException("journalière", dejaConsommeJour + montant, plafondJour);
        }
    }

    public static void verifierLimiteMensuelle(long dejaConsommeMois, long montant, long plafondMois) {
        if (plafondMois > 0 && dejaConsommeMois + montant > plafondMois) {
            throw new LimiteDepasseeException("mensuelle", dejaConsommeMois + montant, plafondMois);
        }
    }

    /** Un OTP est exigé pour toute opération strictement au-delà du seuil (§6.4). */
    public static boolean otpRequis(long montant, long seuil) {
        return seuil > 0 && montant > seuil;
    }
}
