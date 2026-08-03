package ci.masante.payment.web;

import ci.masante.payment.domain.model.Avoir;
import ci.masante.payment.service.ServiceFacturation;
import ci.masante.payment.service.ServiceFacturePdf;
import ci.masante.payment.web.dto.AvoirReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.UUID;

/** API avoirs / notes de crédit (CDC_06 §7.1). Consultation et PDF. */
@RestController
@RequestMapping("/api/v1/credit-notes")
@Tag(name = "Avoirs", description = "Notes de crédit émises lors des corrections/annulations (§7.1)")
public class AvoirController {

    private final ServiceFacturation facturation;
    private final ServiceFacturePdf pdf;

    public AvoirController(ServiceFacturation facturation, ServiceFacturePdf pdf) {
        this.facturation = facturation;
        this.pdf = pdf;
    }

    @GetMapping("/{id}")
    @Operation(summary = "Consulter un avoir")
    public AvoirReponse consulter(@PathVariable UUID id) {
        return AvoirReponse.de(facturation.trouverAvoir(id));
    }

    @GetMapping("/{id}/pdf")
    @Operation(summary = "Télécharger l'avoir en PDF (avec QR)")
    public ResponseEntity<byte[]> telechargerPdf(@PathVariable UUID id) {
        Avoir a = facturation.trouverAvoir(id);
        return ResponseEntity.ok()
                .contentType(MediaType.APPLICATION_PDF)
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=\"" + a.getNumero() + ".pdf\"")
                .body(pdf.genererAvoirPdf(a));
    }
}
