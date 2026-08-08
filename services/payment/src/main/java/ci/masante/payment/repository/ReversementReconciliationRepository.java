package ci.masante.payment.repository;

import ci.masante.payment.domain.model.ReversementReconciliation;
import org.springframework.data.jpa.repository.JpaRepository;

import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface ReversementReconciliationRepository extends JpaRepository<ReversementReconciliation, UUID> {

    /** Rapport existant d'une journée (idempotence : réexécuter recalcule la même ligne). */
    Optional<ReversementReconciliation> findByDateRapport(LocalDate dateRapport);

    /** Rapports récents, du plus récent au plus ancien (consultation). */
    List<ReversementReconciliation> findTop60ByOrderByDateRapportDesc();
}
