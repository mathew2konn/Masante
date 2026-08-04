package ci.masante.payment.repository;

import ci.masante.payment.domain.model.CampagneCashback;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface CampagneCashbackRepository extends JpaRepository<CampagneCashback, UUID> {

    Optional<CampagneCashback> findByCode(String code);

    /** Campagne active pour un type d'op source (au plus une — index unique partiel). */
    Optional<CampagneCashback> findByTypeOperationSourceAndActifTrue(String typeOperationSource);

    /** Verrouille la campagne en écriture : sérialise les contrôles de budget/plafonds concurrents. */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select c from CampagneCashback c where c.id = :id")
    Optional<CampagneCashback> findByIdVerrouille(@Param("id") UUID id);

    List<CampagneCashback> findAllByOrderByCreatedAtDesc();
}
