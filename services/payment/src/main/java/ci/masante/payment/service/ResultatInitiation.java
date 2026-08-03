package ci.masante.payment.service;

import ci.masante.payment.domain.model.Paiement;

/** {@code rejoue} = true quand la clé d'idempotence a renvoyé un paiement déjà existant (HTTP 200). */
public record ResultatInitiation(Paiement paiement, boolean rejoue) {
}
