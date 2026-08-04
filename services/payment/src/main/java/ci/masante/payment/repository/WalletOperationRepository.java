package ci.masante.payment.repository;

import ci.masante.payment.domain.model.WalletOperation;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.Optional;
import java.util.UUID;

public interface WalletOperationRepository extends JpaRepository<WalletOperation, UUID> {

    Optional<WalletOperation> findByIdempotencyKey(String idempotencyKey);

    /** Nombre d'opérations SORTANTES abouties d'un wallet depuis un instant (vélocité, §6.4). */
    @Query("select count(o) from WalletOperation o "
            + "where o.sourceWalletId = :walletId and o.createdAt >= :depuis")
    int compteSortantesDepuis(@Param("walletId") UUID walletId, @Param("depuis") Instant depuis);
}
