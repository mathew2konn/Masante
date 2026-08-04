package ci.masante.payment.web.dto;

/** Sous-soldes de récompense d'un wallet (dérivés, §6.1). Cashback net = accordé − repris. */
public record RecompensesReponse(long totalCashbackNet, long totalBonus) {
}
