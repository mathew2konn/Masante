package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.service.ActionClient;
import ci.masante.payment.service.ResultatCarte;

import java.time.Instant;
import java.util.UUID;

/**
 * Vue publique d'une opération carte (§7). N'expose QUE le {@link PaiementStatut} générique et l'
 * {@link ActionClient} (jamais le {@code StatutCarte} interne, interdit #8). {@code challengeRef} /
 * {@code urlRedirection} ne sont présents qu'à l'initiation (une fois) ; les rejeux ne les renvoient pas.
 */
public record CartePaiementReponse(
        UUID paiementId,
        UUID transactionId,
        PaiementStatut statut,
        ActionClient action,
        String challengeRef,
        String urlRedirection,
        Instant expireLe,
        String codeRefus,
        boolean rejoue
) {
    public static CartePaiementReponse de(ResultatCarte r) {
        return new CartePaiementReponse(r.paiementId(), r.transactionId(), r.statut(), r.action(),
                r.challengeRef(), r.urlRedirection(), r.expireLe(), r.codeRefusPublic(), r.rejoue());
    }
}
