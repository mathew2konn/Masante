package ci.masante.payment.web;

import ci.masante.payment.domain.model.Wallet;
import ci.masante.payment.service.ServiceWallet;
import ci.masante.payment.web.dto.CreerWalletRequete;
import ci.masante.payment.web.dto.MontantOperationRequete;
import ci.masante.payment.web.dto.PayerFactureWalletRequete;
import ci.masante.payment.web.dto.TransfertRequete;
import ci.masante.payment.web.dto.WalletEntryReponse;
import ci.masante.payment.web.dto.WalletOperationReponse;
import ci.masante.payment.web.dto.WalletReponse;
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
 * API Wallet (CDC_06 §6). Création, solde (dérivé), grand livre, crédit/débit/transfert, gel et
 * paiement d'une facture depuis le portefeuille. Les écritures financières exigent {@code Idempotency-Key}.
 */
@RestController
@RequestMapping("/api/v1/wallets")
@Validated
@Tag(name = "Wallet", description = "Portefeuille + comptabilité en double écriture (CDC_06 §6)")
public class WalletController {

    private final ServiceWallet service;

    public WalletController(ServiceWallet service) {
        this.service = service;
    }

    @PostMapping
    @Operation(summary = "Créer (ou retrouver) un portefeuille")
    public ResponseEntity<WalletReponse> creer(@Valid @RequestBody CreerWalletRequete requete) {
        Wallet w = service.creer(requete.ownerRef(), requete.ownerType(), requete.devise());
        return ResponseEntity.status(HttpStatus.CREATED).body(reponse(w));
    }

    @GetMapping("/{id}")
    @Operation(summary = "Consulter un portefeuille et son solde")
    public WalletReponse consulter(@PathVariable UUID id) {
        return reponse(service.trouver(id));
    }

    @GetMapping("/{id}/entries")
    @Operation(summary = "Grand livre : écritures du portefeuille")
    public List<WalletEntryReponse> entries(@PathVariable UUID id) {
        return service.entriesDe(id).stream().map(WalletEntryReponse::de).toList();
    }

    @PostMapping("/{id}/credit")
    @Operation(summary = "Créditer (rechargement simulé)")
    public ResponseEntity<WalletOperationReponse> crediter(
            @PathVariable UUID id,
            @RequestHeader("Idempotency-Key") @NotBlank String cle,
            @Valid @RequestBody MontantOperationRequete r) {
        return created(WalletOperationReponse.de(
                service.crediter(id, r.montant(), r.reference(), r.libelle(), cle)));
    }

    @PostMapping("/{id}/debit")
    @Operation(summary = "Débiter (refusé si solde insuffisant ou portefeuille gelé)")
    public ResponseEntity<WalletOperationReponse> debiter(
            @PathVariable UUID id,
            @RequestHeader("Idempotency-Key") @NotBlank String cle,
            @Valid @RequestBody MontantOperationRequete r) {
        return created(WalletOperationReponse.de(
                service.debiter(id, r.montant(), r.reference(), r.libelle(), cle)));
    }

    @PostMapping("/transfer")
    @Operation(summary = "Transférer entre deux portefeuilles (double écriture)")
    public ResponseEntity<WalletOperationReponse> transferer(
            @RequestHeader("Idempotency-Key") @NotBlank String cle,
            @Valid @RequestBody TransfertRequete r) {
        return created(WalletOperationReponse.de(
                service.transferer(r.sourceWalletId(), r.destWalletId(), r.montant(), r.libelle(), cle)));
    }

    @PostMapping("/{id}/pay-invoice")
    @Operation(summary = "Régler une facture depuis le portefeuille (débit patient → établissement)")
    public ResponseEntity<WalletOperationReponse> payerFacture(
            @PathVariable UUID id,
            @RequestHeader("Idempotency-Key") @NotBlank String cle,
            @Valid @RequestBody PayerFactureWalletRequete r) {
        return created(WalletOperationReponse.de(
                service.payerFacture(id, r.factureId(), r.montant(), cle)));
    }

    @PostMapping("/{id}/freeze")
    @Operation(summary = "Geler un portefeuille (§6.4)")
    public WalletReponse geler(@PathVariable UUID id) {
        return reponse(service.geler(id));
    }

    @PostMapping("/{id}/unfreeze")
    @Operation(summary = "Dégeler un portefeuille")
    public WalletReponse degeler(@PathVariable UUID id) {
        return reponse(service.degeler(id));
    }

    private WalletReponse reponse(Wallet w) {
        return WalletReponse.de(w, service.solde(w.getId()));
    }

    private static ResponseEntity<WalletOperationReponse> created(WalletOperationReponse body) {
        return ResponseEntity.status(HttpStatus.CREATED).body(body);
    }
}
