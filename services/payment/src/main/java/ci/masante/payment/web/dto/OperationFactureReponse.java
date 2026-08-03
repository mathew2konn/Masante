package ci.masante.payment.web.dto;

/** Réponse d'une correction ou annulation : la facture résultante et l'avoir émis. */
public record OperationFactureReponse(FactureReponse facture, AvoirReponse avoir) {
}
