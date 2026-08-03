package ci.masante.payment.web.dto;

/** Corps optionnel de {@code POST /api/v1/payments/{id}/refund}. */
public record RemboursementRequete(String motif) {
}
