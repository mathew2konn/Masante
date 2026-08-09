package ci.masante.payment.web.dto;

import ci.masante.payment.domain.mandat.Periodicite;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.service.CommandeMandat;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import jakarta.validation.constraints.PositiveOrZero;

import java.time.LocalDate;
import java.util.UUID;

/**
 * Requête de création d'un mandat récurrent (§5.4). PCI : aucune donnée de carte ici — seulement la
 * référence d'une carte déjà enrôlée au vault ({@code carteId}).
 */
public record CreerMandatRequete(
        @NotNull UUID carteId,
        @NotNull ObjetPaiement objet,
        String libelle,
        @Positive long montant,
        String devise,
        @NotNull Periodicite periodicite,
        @NotNull LocalDate dateDebut,
        LocalDate dateFin,
        @PositiveOrZero Integer preavisJours,
        String etablissementRef,
        String patientRef
) {
    public CommandeMandat versCommande(String utilisateurRef, String acteur) {
        return new CommandeMandat(
                utilisateurRef, carteId, objet, libelle, montant,
                devise == null || devise.isBlank() ? "XOF" : devise,
                periodicite, dateDebut, dateFin,
                preavisJours == null ? 3 : preavisJours,
                etablissementRef, patientRef, acteur);
    }
}
