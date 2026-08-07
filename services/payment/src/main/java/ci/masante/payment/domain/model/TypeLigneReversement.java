package ci.masante.payment.domain.model;

/**
 * Nature d'une ligne de relevé de reversement (CDC_06 §11).
 * <ul>
 *   <li>{@code FACTURE} — encaissement imputé, porteur de la commission plateforme.</li>
 *   <li>{@code REMBOURSEMENT} — remboursement imputé (décote), sans commission ; peut porter sur une
 *       facture soldée lors d'une période antérieure, déjà imputée sur un relevé précédent.</li>
 * </ul>
 */
public enum TypeLigneReversement {
    FACTURE,
    REMBOURSEMENT
}
