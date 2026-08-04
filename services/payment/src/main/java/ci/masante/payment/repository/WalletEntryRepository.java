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
     * Total DÉBITÉ (sortant, valeur positive) d'un wallet depuis un instant donné — sert au contrôle
     * des limites jour/mois (§6.4). Dérivé du grand livre : aucun compteur n'est stocké.
     */
    @Query("select coalesce(-sum(e.montant), 0) from WalletEntry e "
            + "where e.walletId = :walletId and e.montant < 0 and e.createdAt >= :depuis")
    long debitsDepuis(@Param("walletId") UUID walletId, @Param("depuis") Instant depuis);

    List<WalletEntry> findByWalletIdOrderByCreatedAtAsc(UUID walletId);
}
