package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotBlank;

/** Corps de {@code POST /api/v1/fraud-alerts/{id}/review} : identifiant de l'agent qui revoit. */
public record RevueAlerteRequete(
        @NotBlank(message = "L'identifiant du réviseur est obligatoire.") String revuePar
) {
}
