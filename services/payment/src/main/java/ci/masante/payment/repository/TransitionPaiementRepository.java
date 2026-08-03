package ci.masante.payment.repository;

import ci.masante.payment.domain.model.TransitionPaiement;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface TransitionPaiementRepository extends JpaRepository<TransitionPaiement, UUID> {

    List<TransitionPaiement> findByPaymentIdOrderByCreatedAtAsc(UUID paymentId);
}
