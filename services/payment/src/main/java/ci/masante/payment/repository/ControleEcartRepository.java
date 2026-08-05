package ci.masante.payment.repository;

import ci.masante.payment.domain.model.ControleEcart;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface ControleEcartRepository extends JpaRepository<ControleEcart, UUID> {

    List<ControleEcart> findByRunIdOrderByCreatedAtAsc(UUID runId);
}
