package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.CampagneCashback;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import jakarta.validation.constraints.PositiveOrZero;

import java.time.Instant;

/** Corps de {@code POST /api/v1/cashback-campaigns}. Taux en points de base (500 = 5 %). */
public record CreerCampagneRequete(
        @NotBlank(message = "Le code est obligatoire.") String code,
        @NotBlank(message = "Le libellé est obligatoire.") String libelle,
        @NotBlank(message = "Le type d'opération source est obligatoire.") String typeOperationSource,
        @Positive(message = "Le taux (bps) doit être positif.") int tauxBps,
        @PositiveOrZero(message = "Plafond par opération invalide (0 = illimité).") long plafondParOperation,
        @PositiveOrZero(message = "Plafond par portefeuille invalide (0 = illimité).") long plafondParWallet,
        @PositiveOrZero(message = "Plafond journalier invalide (0 = illimité).") long plafondParWalletParJour,
        Long budgetTotal,
        @NotNull(message = "La date de début est obligatoire.") Instant dateDebut,
        @NotNull(message = "La date de fin est obligatoire.") Instant dateFin
) {
    public CampagneCashback versEntite(String acteur) {
        return new CampagneCashback(code, libelle, typeOperationSource, tauxBps, plafondParOperation,
                plafondParWallet, plafondParWalletParJour, budgetTotal, dateDebut, dateFin, acteur);
    }
}
