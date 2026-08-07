package ci.masante.payment.domain.carte;

/**
 * Devise (ISO 4217) avec son EXPOSANT — le nombre de décimales de sa sous-unité (CDC_06 §5).
 *
 * <p>Le <b>XOF (FCFA) n'a PAS de sous-unité</b> : exposant 0. Un {@code ×100} y fausserait tous les
 * montants. Les montants sont TOUJOURS manipulés et stockés en <b>unité mineure entière</b> ; l'exposant
 * n'est utile qu'à l'affichage/échange avec une passerelle qui exige une convention. Ce type existe
 * pour rendre l'oubli impossible — il ne modifie pas les services existants (montants en {@code long}).</p>
 */
public enum Devise {

    XOF(0),
    EUR(2),
    USD(2);

    private final int exposant;

    Devise(int exposant) {
        this.exposant = exposant;
    }

    /** Nombre de décimales de la sous-unité (0 pour le XOF). */
    public int exposant() {
        return exposant;
    }

    /** Code ISO 4217 (identique au nom de l'enum). */
    public String code() {
        return name();
    }

    /** @throws IllegalArgumentException si le code est nul ou non supporté. */
    public static Devise depuisCode(String code) {
        if (code == null) {
            throw new IllegalArgumentException("Devise nulle");
        }
        return switch (code.trim().toUpperCase()) {
            case "XOF" -> XOF;
            case "EUR" -> EUR;
            case "USD" -> USD;
            default -> throw new IllegalArgumentException("Devise non supportée : " + code);
        };
    }
}
