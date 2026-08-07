package ci.masante.payment.domain.carte;

import java.util.Objects;

/**
 * Montant monétaire immuable : une valeur ENTIÈRE en unité mineure + sa {@link Devise} (CDC_06 §5).
 *
 * <p>Aucun {@code ×100} n'est jamais codé : l'exposant est porté par la devise. Le montant refuse le
 * négatif (un remboursement qui dépasserait le capturé lève ici, au niveau du type). Les opérations
 * exigent la MÊME devise. Type <b>local au domaine carte</b> ; converti en {@code long} à la frontière
 * des services existants (ex. règlement de facture).</p>
 */
public record Montant(long valeur, Devise devise) {

    public Montant {
        Objects.requireNonNull(devise, "devise");
        if (valeur < 0) {
            throw new IllegalArgumentException("Montant négatif interdit : " + valeur);
        }
    }

    public static Montant de(long valeur, Devise devise) {
        return new Montant(valeur, devise);
    }

    public static Montant de(long valeur, String codeDevise) {
        return new Montant(valeur, Devise.depuisCode(codeDevise));
    }

    public Montant plus(Montant autre) {
        exigerMemeDevise(autre);
        return new Montant(valeur + autre.valeur, devise);
    }

    /** @throws IllegalArgumentException si le résultat est négatif (dépassement) ou devise différente. */
    public Montant moins(Montant autre) {
        exigerMemeDevise(autre);
        return new Montant(valeur - autre.valeur, devise);
    }

    public boolean superieurA(Montant autre) {
        exigerMemeDevise(autre);
        return valeur > autre.valeur;
    }

    public boolean estNul() {
        return valeur == 0L;
    }

    private void exigerMemeDevise(Montant autre) {
        if (devise != autre.devise) {
            throw new IllegalArgumentException("Devises incompatibles : " + devise + " vs " + autre.devise);
        }
    }
}
