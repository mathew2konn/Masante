package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;

import java.util.UUID;

/**
 * Corps de {@code POST /api/v1/wallets/{id}/cashback/reverse} (clawback admin ; en production ce chemin
 * est déclenché par le remboursement de l'op source — §11 — dans la même transaction). L'idempotence
 * est portée par {@code remboursementId} : chaque remboursement partiel = une reprise distincte.
 */
public record ReprendreCashbackRequete(
        @NotNull(message = "L'opération source est obligatoire.") UUID operationSourceId,
        @NotNull(message = "L'identifiant de remboursement est obligatoire.") UUID remboursementId,
        @Positive(message = "Le montant remboursé doit être positif.") long montantRembourse,
        @Positive(message = "Le montant de l'opération source doit être positif.") long montantSource,
        boolean soldeOperationSource
) {
}
