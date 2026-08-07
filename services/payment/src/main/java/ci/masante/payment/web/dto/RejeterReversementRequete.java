package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotBlank;

/** Corps de {@code POST /api/v1/settlements/{id}/reject}. Motif obligatoire (piste d'audit). */
public record RejeterReversementRequete(@NotBlank String motif) {
}
