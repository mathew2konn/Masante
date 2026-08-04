package ci.masante.payment.repository;

import ci.masante.payment.domain.model.WalletEntry;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

public interface WalletEntryRepository extends JpaRepository<WalletEntry, UUID> {

    /** Solde = somme des écritures du wallet (le solde n'est jamais stocké — §6.3). */
    @Query("select coalesce(sum(e.montant), 0) from WalletEntry e where e.walletId = :walletId")
    long solde(@Param("walletId") UUID walletId);

    /**
     * Total DÉBITÉ par l'UTILISATEUR (sortant, valeur positive) depuis un instant — sert aux limites
     * jour/mois (§6.4) et au cumul de la détection de fraude. Restreint aux types sortants
     * <b>utilisateur</b> (DEBIT / TRANSFERT / PAIEMENT_FACTURE) : une reprise de cashback
     * (CASHBACK_ANNULATION, écriture négative) ne doit PAS être comptée comme une dépense. Dérivé du
     * grand livre : aucun compteur n'est stocké.
     */
    @Query("select coalesce(-sum(e.montant), 0) from WalletEntry e, WalletOperation o "
            + "where e.operationId = o.id and e.walletId = :walletId and e.montant < 0 "
            + "and e.createdAt >= :depuis and o.type in ("
            + "ci.masante.payment.domain.model.TypeOperationWallet.DEBIT, "
            + "ci.masante.payment.domain.model.TypeOperationWallet.TRANSFERT, "
            + "ci.masante.payment.domain.model.TypeOperationWallet.PAIEMENT_FACTURE)")
    long debitsDepuis(@Param("walletId") UUID walletId, @Param("depuis") Instant depuis);

    List<WalletEntry> findByWalletIdOrderByCreatedAtAsc(UUID walletId);
}
