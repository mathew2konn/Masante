package ci.masante.payment.repository;

import ci.masante.payment.domain.model.LigneReversement;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface LigneReversementRepository extends JpaRepository<LigneReversement, UUID> {

    List<LigneReversement> findByReleveIdOrderByCreatedAtAsc(UUID releveId);
}
