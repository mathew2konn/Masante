package ci.masante.payment.domain.gateway;

/**
 * Contrat d'une passerelle de paiement (CDC_06 §3.3 — OCP obligatoire).
 *
 * <p><b>Interdiction absolue</b> d'un {@code if (canal == "orange") … else if ("wave") …}. Ajouter
 * un moyen de paiement = ajouter une implémentation de cette interface, sans modifier le code
 * existant. La sélection se fait par {@link #supporte(String)} via {@code RegistrePasserelles}.</p>
 */
public interface PasserellePaiement {

    /** @return true si cette passerelle prend en charge le canal (ex. {@code "orange_money"}). */
    boolean supporte(String canal);

    /** Initie/exécute le paiement auprès du prestataire. */
    ResultatPaiement payer(RequetePaiement requete);

    /** Interroge le statut d'une transaction (réconciliation — §4.5). */
    ResultatPaiement statut(String referenceOperateur);

    /** Rembourse une transaction déjà réussie. */
    ResultatRemboursement rembourser(RequeteRemboursement requete);
}
