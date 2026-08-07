package ci.masante.payment.web.dto;

import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.service.CommandeCarte;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;

import java.util.UUID;

/**
 * Corps de {@code POST /api/v1/card-payments} (§7.1). FRONTIÈRE PCI : {@code referenceClient} est un TOKEN
 * ou une référence de session fournie par la passerelle — JAMAIS un PAN/CVV (le filtre anti-PAN rejette
 * tout ce qui ressemble à un numéro de carte avant même d'atteindre ce point, §9).
 */
public record InitierCartePaiementRequete(
        @NotBlank(message = "Le PSP est obligatoire.") String psp,
        @NotBlank(message = "La référence client (token/session) est obligatoire.") String referenceClient,
        @Positive(message = "Le montant doit être strictement positif.") long montant,
        String devise,
        @NotNull(message = "L'objet du paiement est obligatoire.") ObjetPaiement objet,
        String returnUrl,
        boolean enregistrerCarte,
        String correlationId,
        String etablissementRef,
        String patientRef,
        UUID factureId
) {
    /** {@code utilisateurRef} provient de l'en-tête posé par la passerelle (jamais du corps). */
    public CommandeCarte versCommande(String utilisateurRef) {
        Montant m = Montant.de(montant, devise == null || devise.isBlank() ? "XOF" : devise);
        return new CommandeCarte(psp, referenceClient, m, utilisateurRef, objet, returnUrl,
                enregistrerCarte, correlationId, etablissementRef, patientRef, factureId);
    }
}
