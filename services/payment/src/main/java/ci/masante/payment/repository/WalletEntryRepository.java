package ci.masante.payment.repository;

import ci.masante.payment.domain.model.WalletEntry;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;
import java.util.UUID;

public interface WalletEntryRepository extends JpaRepository<WalletEntry, UUID> {

    /** Solde = somme des écritures du wallet (le solde n'est jamais stocké — §6.3). */
    @Query("select coalesce(sum(e.montant), 0) from WalletEntry e where e.walletId = :walletId")
    long solde(@Param("walletId") UUID walletId);

    List<WalletEntry> findByWalletIdOrderByCreatedAtAsc(UUID walletId);
}
