package ci.masante.payment.web;

import ci.masante.payment.service.ServiceRecompense;
import ci.masante.payment.service.ServiceWallet;
import ci.masante.payment.web.dto.AccorderBonusRequete;
import ci.masante.payment.web.dto.CampagneReponse;
import ci.masante.payment.web.dto.CashbackReponse;
import ci.masante.payment.web.dto.CreerCampagneRequete;
import ci.masante.payment.web.dto.EvaluerCashbackRequete;
import ci.masante.payment.web.dto.RecompensesReponse;
import ci.masante.payment.web.dto.ReprendreCashbackRequete;
import ci.masante.payment.web.dto.WalletOperationReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.UUID;

/**
 * Cashback (campagnes) + Bonus (CDC_06 §6.1/§6.2). L'<b>acteur</b> des actes de création monétaire
 * (bonus, campagnes, clawback) vient de l'en-tête {@code X-Acteur-Id} <b>posé par la passerelle
 * authentifiée</b> — jamais du corps (non usurpable). L'en-tête absent = requête rejetée.
 */
@RestController
@RequestMapping("/api/v1")
@Validated
@Tag(name = "Récompenses", description = "Cashback (campagnes) + Bonus (CDC_06 §6.1/§6.2)")
public class RecompenseController {

    private final ServiceRecompense recompense;
    private final ServiceWallet wallet;

    public RecompenseController(ServiceRecompense recompense, ServiceWallet wallet) {
        this.recompense = recompense;
        this.wallet = wallet;
    }

    // --- campagnes ----------------------------------------------------------------------------

    @PostMapping("/cashback-campaigns")
    @Operation(summary = "Créer une campagne de cashback (acteur via X-Acteur-Id)")
    public ResponseEntity<CampagneReponse> creerCampagne(
            @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @Valid @RequestBody CreerCampagneRequete r) {
        return ResponseEntity.status(HttpStatus.CREATED)
                .body(CampagneReponse.de(recompense.creerCampagne(r.versEntite(acteur), acteur)));
    }

    @GetMapping("/cashback-campaigns")
    @Operation(summary = "Lister les campagnes")
    public List<CampagneReponse> listerCampagnes() {
        return recompense.listerCampagnes().stream().map(CampagneReponse::de).toList();
    }

    @PostMapping("/cashback-campaigns/{id}/deactivate")
    @Operation(summary = "Désactiver une campagne (acteur via X-Acteur-Id)")
    public CampagneReponse desactiver(
            @PathVariable UUID id, @RequestHeader("X-Acteur-Id") @NotBlank String acteur) {
        return CampagneReponse.de(recompense.desactiverCampagne(id, acteur));
    }

    // --- bonus (actif) ------------------------------------------------------------------------

    @PostMapping("/wallets/{id}/bonus")
    @Operation(summary = "Accorder un bonus (création monétaire ; acteur via X-Acteur-Id)")
    public ResponseEntity<WalletOperationReponse> accorderBonus(
            @PathVariable UUID id,
            @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @RequestHeader("Idempotency-Key") @NotBlank String cle,
            @Valid @RequestBody AccorderBonusRequete r) {
        return ResponseEntity.status(HttpStatus.CREATED).body(WalletOperationReponse.de(
                recompense.accorderBonus(id, r.montant(), r.motif(), acteur, cle)));
    }

    // --- cashback (gaté OFF par défaut : dry-run) ---------------------------------------------

    @PostMapping("/wallets/{id}/cashback")
    @Operation(summary = "Évaluer/accorder le cashback d'une op source (campagne résolue par le serveur)")
    public CashbackReponse cashback(
            @PathVariable UUID id, @Valid @RequestBody EvaluerCashbackRequete r) {
        return CashbackReponse.de(recompense.evaluerCashback(id, r.operationSourceId()));
    }

    @PostMapping("/wallets/{id}/cashback/reverse")
    @Operation(summary = "Reprendre (clawback) le cashback d'une op source remboursée (acteur via en-tête)")
    public CashbackReponse reprendre(
            @PathVariable UUID id,
            @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @Valid @RequestBody ReprendreCashbackRequete r) {
        return CashbackReponse.de(recompense.annulerCashback(r.operationSourceId(), r.remboursementId(),
                r.montantRembourse(), r.montantSource(), r.soldeOperationSource(), acteur));
    }

    @GetMapping("/wallets/{id}/rewards")
    @Operation(summary = "Sous-soldes de récompense (cashback net, bonus)")
    public RecompensesReponse rewards(@PathVariable UUID id) {
        return new RecompensesReponse(wallet.totalCashbackNet(id), wallet.totalBonus(id));
    }
}
