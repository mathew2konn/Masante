package ci.masante.payment.web;

import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.service.OperationFacture;
import ci.masante.payment.service.ServiceFacturation;
import ci.masante.payment.service.ServiceFacturePdf;
import ci.masante.payment.web.dto.AnnulerFactureRequete;
import ci.masante.payment.web.dto.AvoirReponse;
import ci.masante.payment.web.dto.CorrigerFactureRequete;
import ci.masante.payment.web.dto.CreerFactureRequete;
import ci.masante.payment.web.dto.FactureReponse;
import ci.masante.payment.web.dto.OperationFactureReponse;
import ci.masante.payment.web.dto.VerificationSignatureReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.UUID;

/**
 * API facturation (CDC_06 §7). Émission, consultation, PDF/QR, <b>correction versionnée</b>,
 * <b>annulation</b> (avec avoir), <b>versions</b> et <b>vérification de signature</b>.
 * <b>Frontière</b> : tous les montants sont calculés côté service.
 */
@RestController
@RequestMapping("/api/v1/invoices")
@Tag(name = "Factures", description = "Émission, PDF/QR, correction/versionnage, annulation, avoir, signature (§7)")
public class FactureController {

    private final ServiceFacturation facturation;
    private final ServiceFacturePdf pdf;

    public FactureController(ServiceFacturation facturation, ServiceFacturePdf pdf) {
        this.facturation = facturation;
        this.pdf = pdf;
    }

    @PostMapping
    @Operation(summary = "Émettre une facture (calcule HT, TVA, remises, prise en charge, reste à payer)")
    public ResponseEntity<FactureReponse> creer(@Valid @RequestBody CreerFactureRequete requete) {
        Facture f = facturation.creer(requete.versEntree());
        return ResponseEntity.status(HttpStatus.CREATED).body(reponse(f));
    }

    @GetMapping("/{id}")
    @Operation(summary = "Consulter une facture")
    public FactureReponse consulter(@PathVariable UUID id) {
        return reponse(facturation.trouver(id));
    }

    @GetMapping("/{id}/pdf")
    @Operation(summary = "Télécharger la facture en PDF (avec QR de vérification)")
    public ResponseEntity<byte[]> telechargerPdf(@PathVariable UUID id) {
        Facture f = facturation.trouver(id);
        byte[] contenu = pdf.genererPdf(f, facturation.lignesDe(f.getId()));
        return ResponseEntity.ok()
                .contentType(MediaType.APPLICATION_PDF)
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=\"" + f.getNumero() + ".pdf\"")
                .body(contenu);
    }

    @PostMapping("/{id}/corriger")
    @Operation(summary = "Corriger une facture : nouvelle version + avoir du TTC d'origine (§7.5)")
    public ResponseEntity<OperationFactureReponse> corriger(@PathVariable UUID id,
                                                            @Valid @RequestBody CorrigerFactureRequete requete) {
        OperationFacture op = facturation.corriger(id, requete.versLignes(), requete.remiseGlobale(),
                requete.versParametres(), requete.motif());
        return ResponseEntity.status(HttpStatus.CREATED).body(operation(op));
    }

    @PostMapping("/{id}/annuler")
    @Operation(summary = "Annuler une facture : statut ANNULEE + avoir du TTC (§7.1)")
    public OperationFactureReponse annuler(@PathVariable UUID id,
                                           @RequestBody(required = false) AnnulerFactureRequete requete) {
        OperationFacture op = facturation.annuler(id, requete == null ? null : requete.motif());
        return operation(op);
    }

    @GetMapping("/{id}/versions")
    @Operation(summary = "Lister toutes les versions de la lignée d'une facture (§7.5)")
    public List<FactureReponse> versions(@PathVariable UUID id) {
        return facturation.versions(id).stream().map(this::reponse).toList();
    }

    @GetMapping("/{id}/credit-notes")
    @Operation(summary = "Lister les avoirs liés à une facture")
    public List<AvoirReponse> avoirs(@PathVariable UUID id) {
        facturation.trouver(id); // 404 si la facture n'existe pas
        return facturation.avoirsDe(id).stream().map(AvoirReponse::de).toList();
    }

    @GetMapping("/{id}/verify-signature")
    @Operation(summary = "Vérifier l'intégrité (hash) et la signature de la facture (§7.4)")
    public VerificationSignatureReponse verifierSignature(@PathVariable UUID id) {
        return VerificationSignatureReponse.de(facturation.verifierSignatureFacture(id));
    }

    private FactureReponse reponse(Facture f) {
        return FactureReponse.de(f, facturation.lignesDe(f.getId()));
    }

    private OperationFactureReponse operation(OperationFacture op) {
        return new OperationFactureReponse(reponse(op.facture()), AvoirReponse.de(op.avoir()));
    }
}
