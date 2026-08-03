package ci.masante.payment.web.dto;

import ci.masante.payment.service.VerificationSignature;

/** Vue publique de la vérification d'intégrité et de signature d'une facture (§7.4). */
public record VerificationSignatureReponse(
        boolean integre,
        boolean signee,
        boolean signatureValide,
        String algorithme
) {
    public static VerificationSignatureReponse de(VerificationSignature v) {
        return new VerificationSignatureReponse(v.integre(), v.signee(), v.signatureValide(), v.algorithme());
    }
}
