package ci.masante.payment.web;

import ci.masante.payment.service.ServicePriseEnCharge;
import ci.masante.payment.web.dto.CouvertureReponse;
import ci.masante.payment.web.dto.QuoteCouvertureRequete;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

/**
 * API de prise en charge CNAM / assurance (CDC_06 §8). Renvoie couverture, ticket modérateur et
 * reste à charge. <b>Frontière</b> : ces montants sont calculés ici et nulle part ailleurs.
 */
@RestController
@RequestMapping("/api/v1/coverage")
@Tag(name = "Prise en charge", description = "Calcul couverture / ticket modérateur / reste à charge (CNAM, assurance)")
public class CouvertureController {

    private final ServicePriseEnCharge priseEnCharge;

    public CouvertureController(ServicePriseEnCharge priseEnCharge) {
        this.priseEnCharge = priseEnCharge;
    }

    @PostMapping("/quote")
    @Operation(summary = "Calculer la prise en charge d'un montant")
    public CouvertureReponse quote(@Valid @RequestBody QuoteCouvertureRequete requete) {
        return CouvertureReponse.de(priseEnCharge.calculer(requete.versRequete()));
    }
}
