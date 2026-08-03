package ci.masante.payment.repository;

import ci.masante.payment.domain.model.WalletOperation;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;
import java.util.UUID;

public interface WalletOperationRepository extends JpaRepository<WalletOperation, UUID> {

    Optional<WalletOperation> findByIdempotencyKey(String idempotencyKey);
}
