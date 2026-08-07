package ci.masante.payment.web.dto;

import jakarta.validation.constraints.Positive;

/**
 * Corps de {@code POST /api/v1/card-payments/{id}/refund} (§7.2). Le montant permet le remboursement
 * PARTIEL ; le contrôle « cumul remboursé ≤ capturé » est fait côté serveur (frontière). Devise optionnelle
 * (défaut : celle de la transaction, XOF).
 */
public record RembourserCarteRequete(
        @Positive(message = "Le montant du remboursement doit être strictement positif.") long montant,
        String devise,
        String motif
) {
}
