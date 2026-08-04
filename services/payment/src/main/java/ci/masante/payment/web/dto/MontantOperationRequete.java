package ci.masante.payment.web.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.Positive;

/**
 * Corps d'un crédit ou d'un débit de wallet. Montant en FCFA.
 * Un débit (opération sortante) exige le {@code pin} ; l'{@code otp} n'est requis qu'au-delà du seuil (§6.4).
 */
public record MontantOperationRequete(
        @Positive(message = "Le montant doit être strictement positif.") long montant,
        String reference,
        String libelle,
        @Schema(description = "PIN wallet (requis pour un débit).") String pin,
        @Schema(description = "OTP (requis au-delà du seuil de montant).") String otp
) {
    @Override
    public String toString() { // ne jamais divulguer PIN/OTP dans les logs
        return "MontantOperationRequete[montant=" + montant + ", reference=" + reference
                + ", libelle=" + libelle + ", pin=***, otp=***]";
    }
}
