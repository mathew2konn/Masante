package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.service.CommandePaiement;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;

import java.util.UUID;

/** Corps de {@code POST /api/v1/payments}. Montant en FCFA (entier positif). */
public record InitierPaiementRequete(
        @Positive(message = "Le montant doit être strictement positif.") long montant,
        String devise,
        @NotBlank(message = "Le canal de paiement est obligatoire.") String canal,
        @NotNull(message = "L'objet du paiement est obligatoire.") ObjetPaiement objet,
        String telephone,
        String correlationId,
        String etablissementRef,
        String patientRef,
        UUID factureId
) {
    public CommandePaiement versCommande() {
        return new CommandePaiement(montant, devise, canal, objet, telephone,
                correlationId, etablissementRef, patientRef, factureId);
    }
}
