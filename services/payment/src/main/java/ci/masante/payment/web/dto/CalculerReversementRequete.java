package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;

import java.time.Instant;

/**
 * Corps de {@code POST /api/v1/settlements/run}. Fenêtre semi-ouverte [debut, fin). {@code cutOff}
 * facultatif (défaut = fin de période) ; doit être ≥ fin. L'acteur vient de l'en-tête X-Acteur-Id.
 */
public record CalculerReversementRequete(
        @NotBlank String etablissementRef,
        @NotNull Instant periodeDebut,
        @NotNull Instant periodeFin,
        Instant cutOff) {
}
