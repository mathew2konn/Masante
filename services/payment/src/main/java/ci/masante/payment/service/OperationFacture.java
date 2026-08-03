package ci.masante.payment.service;

import ci.masante.payment.domain.model.Avoir;
import ci.masante.payment.domain.model.Facture;

/**
 * Résultat d'une correction ou annulation : la facture concernée (nouvelle version pour une
 * correction ; facture annulée pour une annulation) et l'avoir émis.
 */
public record OperationFacture(Facture facture, Avoir avoir) {
}
