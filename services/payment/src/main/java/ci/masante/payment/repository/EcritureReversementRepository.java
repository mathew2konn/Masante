package ci.masante.payment.repository;

import ci.masante.payment.domain.model.EcritureReversement;
import ci.masante.payment.domain.model.TypeEcriture;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface EcritureReversementRepository extends JpaRepository<EcritureReversement, UUID> {

    List<EcritureReversement> findByReleveIdOrderByCreeLeAsc(UUID releveId);

    Optional<EcritureReversement> findByReleveIdAndTypeEcriture(UUID releveId, TypeEcriture typeEcriture);
}
