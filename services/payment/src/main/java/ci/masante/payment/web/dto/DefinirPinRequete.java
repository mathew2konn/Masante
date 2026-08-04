package ci.masante.payment.web.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;

/** Corps de {@code POST /api/v1/wallets/{id}/pin}. {@code ancienPin} exigé pour un changement. */
public record DefinirPinRequete(
        @NotBlank(message = "Le nouveau PIN est obligatoire.")
        @Schema(description = "Nouveau PIN (4 à 6 chiffres).") String pin,
        @Schema(description = "Ancien PIN (obligatoire si un PIN existe déjà).") String ancienPin
) {
    @Override
    public String toString() {
        return "DefinirPinRequete[pin=***, ancienPin=***]";
    }
}
