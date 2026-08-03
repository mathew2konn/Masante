package ci.masante.payment.domain.gateway;

import ci.masante.payment.domain.model.PaiementStatut;

/**
 * Résultat renvoyé par une passerelle. {@code statut} est un état de la machine (§4.2) ;
 * {@code referenceOperateur} est la référence chez le prestataire (factice en simulé).
 */
public record ResultatPaiement(
        PaiementStatut statut,
        String referenceOperateur,
        String message
) {
}
