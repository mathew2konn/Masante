package ci.masante.payment.repository;

import ci.masante.payment.domain.model.Avoir;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface AvoirRepository extends JpaRepository<Avoir, UUID> {

    List<Avoir> findByFactureIdOrderByCreatedAtAsc(UUID factureId);
}
