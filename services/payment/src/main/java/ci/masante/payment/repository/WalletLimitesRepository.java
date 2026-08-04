package ci.masante.payment.repository;

import ci.masante.payment.domain.model.WalletLimites;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.UUID;

public interface WalletLimitesRepository extends JpaRepository<WalletLimites, UUID> {
}
