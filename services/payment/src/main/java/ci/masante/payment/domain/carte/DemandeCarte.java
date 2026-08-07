package ci.masante.payment.domain.carte;

/**
 * Demande d'initiation d'un paiement carte transmise à la passerelle. FRONTIÈRE PCI : {@code referenceClient}
 * est un TOKEN / une référence de session fournie par la passerelle — JAMAIS un PAN ni un CVV.
 *
 * @param referenceClient token (tokenisée) ou déclencheur de session hébergée (redirigée).
 * @param montant         montant à autoriser (type {@link Montant}, unité mineure + devise).
 * @param utilisateurRef  référence de l'utilisateur (posée par la passerelle) — vault + contrôle de propriété.
 * @param returnUrl       URL de retour après paiement hébergé (modalité redirigée) ; null sinon.
 */
public record DemandeCarte(String referenceClient, Montant montant, String utilisateurRef, String returnUrl) {
}
