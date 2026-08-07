package ci.masante.payment.service;

import ci.masante.payment.domain.model.PaiementStatut;

import java.time.Instant;
import java.util.UUID;

/**
 * Résultat applicatif d'une opération carte, tel qu'exposé au front (§7). Ne porte que le
 * {@link PaiementStatut} GÉNÉRIQUE (jamais le {@code StatutCarte} interne, interdit #8) et l'action à
 * accomplir. {@code challengeRef} / {@code urlRedirection} ne sont renseignés qu'à l'initiation (une fois),
 * jamais rejoués : le serveur ne conserve que le HASH du {@code challengeRef}.
 *
 * @param rejoue true si la réponse provient d'un rejeu idempotent (aucune opération n'a été réexécutée).
 */
public record ResultatCarte(
        UUID paiementId,
        UUID transactionId,
        PaiementStatut statut,
        ActionClient action,
        String challengeRef,
        String urlRedirection,
        Instant expireLe,
        String codeRefusPublic,
        boolean rejoue
) {
}
