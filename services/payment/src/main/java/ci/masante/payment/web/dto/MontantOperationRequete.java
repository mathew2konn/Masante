package ci.masante.payment.web.dto;

import jakarta.validation.constraints.Positive;

/** Corps d'un crédit ou d'un débit de wallet. Montant en FCFA. */
public record MontantOperationRequete(
        @Positive(message = "Le montant doit être strictement positif.") long montant,
        String reference,
        String libelle
) {
}
