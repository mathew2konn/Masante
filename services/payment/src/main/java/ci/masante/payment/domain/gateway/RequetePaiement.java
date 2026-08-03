package ci.masante.payment.domain.gateway;

import ci.masante.payment.domain.model.ObjetPaiement;

/**
 * Requête transmise à une passerelle (CDC_06 §3.3). Immuable. Le montant est en FCFA (entier).
 */
public record RequetePaiement(
        String referenceInterne,
        long montant,
        String devise,
        String canal,
        ObjetPaiement objet,
        String telephone,
        String correlationId
) {
}
