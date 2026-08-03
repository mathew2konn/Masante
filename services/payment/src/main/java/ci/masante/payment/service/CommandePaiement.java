package ci.masante.payment.service;

import ci.masante.payment.domain.model.ObjetPaiement;

import java.util.UUID;

/** Commande applicative d'initiation de paiement (issue de la requête HTTP validée). */
public record CommandePaiement(
        long montant,
        String devise,
        String canal,
        ObjetPaiement objet,
        String telephone,
        String correlationId,
        String etablissementRef,
        String patientRef,
        UUID factureId
) {
}
