package ci.masante.payment.domain.model;

/**
 * Nature d'une écriture du grand livre reversement (CDC_06 §11).
 * <ul>
 *   <li>{@code CONSTATATION} — reconnaissance de la dette à l'approbation (P5.5b-1).</li>
 *   <li>{@code DECAISSEMENT} — versement effectif (P5.5b-2).</li>
 *   <li>{@code EXTOURNE} — contre-passation : écriture inverse référençant l'originale (append-only).</li>
 * </ul>
 */
public enum TypeEcriture {
    CONSTATATION,
    DECAISSEMENT,
    EXTOURNE
}
