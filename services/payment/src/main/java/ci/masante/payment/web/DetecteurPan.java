package ci.masante.payment.web;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Détecteur de numéro de carte (PAN) en clair — défense en profondeur PCI (§9). Repère toute suite de
 * 13 à 19 chiffres (séparateurs espace/tiret tolérés, format « 4242 4242 4242 4242 ») qui satisfait la
 * clé de Luhn. Un faux positif (rejet d'un identifiant numérique Luhn-valide) est un compromis ASSUMÉ :
 * la sécurité prime, et aucune donnée métier légitime n'a besoin d'un entier de 13-19 chiffres.
 *
 * <p>Classe <b>pure</b> (aucune I/O) → testable en unitaire.</p>
 */
public final class DetecteurPan {

    /** 13 à 19 chiffres, avec au plus un séparateur espace/tiret entre deux chiffres. */
    private static final Pattern CANDIDAT = Pattern.compile("\\d(?:[ -]?\\d){12,18}");

    private DetecteurPan() {
    }

    public static boolean contientPan(String texte) {
        if (texte == null || texte.isEmpty()) {
            return false;
        }
        Matcher m = CANDIDAT.matcher(texte);
        while (m.find()) {
            String chiffres = m.group().replaceAll("[ -]", "");
            if (chiffres.length() >= 13 && chiffres.length() <= 19 && luhnValide(chiffres)) {
                return true;
            }
        }
        return false;
    }

    /** Algorithme de Luhn (checksum mod 10) — celui qu'utilise tout numéro de carte. */
    private static boolean luhnValide(String numero) {
        int somme = 0;
        boolean doubler = false;
        for (int i = numero.length() - 1; i >= 0; i--) {
            int d = numero.charAt(i) - '0';
            if (doubler) {
                d *= 2;
                if (d > 9) {
                    d -= 9;
                }
            }
            somme += d;
            doubler = !doubler;
        }
        return somme % 10 == 0;
    }
}
