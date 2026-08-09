package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotEmpty;
import jakarta.validation.constraints.Size;

import java.time.Instant;
import java.util.List;

/**
 * Requête d'extraction de signaux en LOT (§6.9 comportements anormaux). Liste bornée de références de
 * factures ; {@code asOf} optionnel = cut-off T de reproductibilité (défaut = maintenant). La borne de
 * taille protège d'un balayage abusif via un endpoint sensible.
 */
public record SignauxLotRequete(
        @NotEmpty @Size(max = 500) List<String> references,
        Instant asOf) {
}
