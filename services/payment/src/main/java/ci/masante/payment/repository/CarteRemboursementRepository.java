package ci.masante.payment.repository;

import ci.masante.payment.domain.model.CarteRemboursement;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface CarteRemboursementRepository extends JpaRepository<CarteRemboursement, UUID> {

    List<CarteRemboursement> findByCarteTransactionId(UUID carteTransactionId);

    Optional<CarteRemboursement> findByPspAndRefPasserelleRemboursement(String psp, String ref);
}
