package ci.masante.payment.repository;

import ci.masante.payment.domain.model.FactureLigne;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface FactureLigneRepository extends JpaRepository<FactureLigne, UUID> {

    List<FactureLigne> findByFactureIdOrderByOrdreAsc(UUID factureId);
}
