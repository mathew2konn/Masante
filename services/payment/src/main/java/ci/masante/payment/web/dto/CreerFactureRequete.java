package ci.masante.payment.web.dto;

import ci.masante.payment.domain.billing.EntreeFacturation;
import ci.masante.payment.domain.billing.LigneEntree;
import ci.masante.payment.domain.billing.ParametresPriseEnCharge;
import ci.masante.payment.domain.coverage.TypePriseEnCharge;
import jakarta.validation.Valid;
import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotEmpty;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import jakarta.validation.constraints.PositiveOrZero;

import java.util.List;

/** Corps de {@code POST /api/v1/invoices} (CDC_06 §7). Montants en FCFA. */
public record CreerFactureRequete(
        @NotBlank(message = "L'établissement est obligatoire.") String etablissementRef,
        String patientRef,
        Integer exercice,
        String devise,
        @NotEmpty(message = "Au moins une ligne est requise.") @Valid List<LigneRequete> lignes,
        @PositiveOrZero(message = "La remise globale ne peut pas être négative.") long remiseGlobale,
        @Valid PriseEnChargeRequete priseEnCharge
) {
    public EntreeFacturation versEntree() {
        List<LigneEntree> l = lignes.stream()
                .map(x -> new LigneEntree(x.libelle(), x.quantite(), x.prixUnitaire(), x.remise(), x.tauxTva()))
                .toList();
        ParametresPriseEnCharge pec = priseEnCharge == null ? null : new ParametresPriseEnCharge(
                priseEnCharge.type(), priseEnCharge.tauxCouverture(), priseEnCharge.plafond(), priseEnCharge.exclu());
        return new EntreeFacturation(etablissementRef, patientRef, exercice, devise, l, remiseGlobale, pec);
    }

    /** Une ligne de facture en entrée. */
    public record LigneRequete(
            @NotBlank(message = "Le libellé est obligatoire.") String libelle,
            @Positive(message = "La quantité doit être positive.") int quantite,
            @PositiveOrZero(message = "Le prix unitaire ne peut pas être négatif.") long prixUnitaire,
            @PositiveOrZero(message = "La remise ne peut pas être négative.") long remise,
            @Min(value = 0, message = "TVA ≥ 0.") @Max(value = 100, message = "TVA ≤ 100.") int tauxTva
    ) {
    }

    /** Prise en charge optionnelle (sans montant : il vaut le TTC calculé). */
    public record PriseEnChargeRequete(
            @NotNull(message = "Le type de prise en charge est obligatoire.") TypePriseEnCharge type,
            @Min(value = 0, message = "Taux ≥ 0.") @Max(value = 100, message = "Taux ≤ 100.") int tauxCouverture,
            @PositiveOrZero(message = "Le plafond ne peut pas être négatif.") Long plafond,
            boolean exclu
    ) {
    }
}
