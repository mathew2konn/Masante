package ci.masante.payment.web;

import ci.masante.payment.service.ServiceDetectionFraude;
import ci.masante.payment.web.dto.FraudAlerteReponse;
import ci.masante.payment.web.dto.RevueAlerteRequete;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.UUID;

/**
 * Alertes de fraude (CDC_06 §6.4, §9.10). Consultation pour surveillance/revue ; la revue marque
 * l'alerte traitée mais <b>ne dégèle pas</b> le portefeuille (le dégel reste {@code /wallets/{id}/unfreeze}).
 */
@RestController
@RequestMapping("/api/v1/fraud-alerts")
@Tag(name = "Fraude", description = "Détection de fraude par règles + gel sur suspicion (CDC_06 §6.4)")
public class FraudController {

    private final ServiceDetectionFraude detection;

    public FraudController(ServiceDetectionFraude detection) {
        this.detection = detection;
    }

    @GetMapping
    @Operation(summary = "Lister les alertes OUVERTES (à revoir)")
    public List<FraudAlerteReponse> ouvertes() {
        return detection.alertesOuvertes().stream().map(FraudAlerteReponse::de).toList();
    }

    @GetMapping("/wallet/{walletId}")
    @Operation(summary = "Lister les alertes d'un portefeuille")
    public List<FraudAlerteReponse> parWallet(@PathVariable UUID walletId) {
        return detection.alertesDe(walletId).stream().map(FraudAlerteReponse::de).toList();
    }

    @PostMapping("/{id}/review")
    @Operation(summary = "Marquer une alerte comme revue (ne dégèle pas le portefeuille)")
    public FraudAlerteReponse revoir(@PathVariable UUID id, @Valid @RequestBody RevueAlerteRequete r) {
        return FraudAlerteReponse.de(detection.marquerRevue(id, r.revuePar()));
    }
}
