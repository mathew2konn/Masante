package ci.masante.payment.web.dto;

import ci.masante.payment.domain.billing.LigneEntree;
import ci.masante.payment.domain.billing.ParametresPriseEnCharge;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotEmpty;
import jakarta.validation.constraints.PositiveOrZero;

import java.util.List;

/**
 * Corps de {@code POST /api/v1/invoices/{id}/corriger}. Établissement, patient et exercice sont
 * hérités de la facture d'origine ; on ne fournit que le contenu corrigé.
 */
public record CorrigerFactureRequete(
        @NotEmpty(message = "Au moins une ligne est requise.") @Valid List<CreerFactureRequete.LigneRequete> lignes,
        @PositiveOrZero(message = "La remise globale ne peut pas être négative.") long remiseGlobale,
        @Valid CreerFactureRequete.PriseEnChargeRequete priseEnCharge,
        String motif
) {
    public List<LigneEntree> versLignes() {
        return lignes.stream()
                .map(x -> new LigneEntree(x.libelle(), x.quantite(), x.prixUnitaire(), x.remise(), x.tauxTva()))
                .toList();
    }

    public ParametresPriseEnCharge versParametres() {
        return priseEnCharge == null ? null : new ParametresPriseEnCharge(
                priseEnCharge.type(), priseEnCharge.tauxCouverture(), priseEnCharge.plafond(), priseEnCharge.exclu());
    }
}
