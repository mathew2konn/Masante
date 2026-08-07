package ci.masante.payment.repository;

import ci.masante.payment.domain.model.LigneGrandLivre;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface LigneGrandLivreRepository extends JpaRepository<LigneGrandLivre, UUID> {

    List<LigneGrandLivre> findByEcritureIdOrderBySequenceAsc(UUID ecritureId);
}
