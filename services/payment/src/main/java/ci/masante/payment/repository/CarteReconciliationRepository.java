package ci.masante.payment.repository;

import ci.masante.payment.domain.model.CarteReconciliation;
import org.springframework.data.jpa.repository.JpaRepository;

import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface CarteReconciliationRepository extends JpaRepository<CarteReconciliation, UUID> {

    /** Rapport existant d'une journée/PSP (idempotence : réexécuter recalcule la même ligne). */
    Optional<CarteReconciliation> findByDateRapportAndPsp(LocalDate dateRapport, String psp);

    List<CarteReconciliation> findByDateRapportOrderByPsp(LocalDate dateRapport);
}
