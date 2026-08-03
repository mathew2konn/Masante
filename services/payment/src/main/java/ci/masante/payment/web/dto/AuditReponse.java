package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.EntreeAudit;

import java.time.Instant;

public record AuditReponse(
        long sequence,
        String evenement,
        String refType,
        String refId,
        String payload,
        String previousHash,
        String hash,
        Instant horodatage
) {
    public static AuditReponse de(EntreeAudit e) {
        return new AuditReponse(e.getSequence(), e.getEvenement(), e.getRefType(), e.getRefId(),
                e.getPayload(), e.getPreviousHash(), e.getHash(), e.getCreatedAt());
    }
}
