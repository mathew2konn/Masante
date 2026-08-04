package ci.masante.payment.repository;

import ci.masante.payment.domain.model.WalletPin;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.UUID;

public interface WalletPinRepository extends JpaRepository<WalletPin, UUID> {
}
