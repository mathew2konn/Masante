package ci.masante.payment.repository.projection;

/**
 * Acte « principal » d'une facture = la ligne au montant TTC le plus élevé (le geste central facturé).
 * Sert à comparer le montant facturé à sa moyenne de référence historique (règle « montant aberrant »).
 */
public interface ActePrincipalProj {
    String getLibelle();

    long getMontant();
}
