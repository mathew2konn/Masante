package ci.masante.payment.repository;

import ci.masante.payment.domain.mandat.StatutEcheance;
import ci.masante.payment.domain.model.MandatEcheance;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.LocalDate;
import java.util.Collection;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface MandatEcheanceRepository extends JpaRepository<MandatEcheance, UUID> {

    List<MandatEcheance> findByMandatIdOrderByNumeroSequence(UUID mandatId);

    boolean existsByMandatIdAndNumeroSequence(UUID mandatId, int numeroSequence);

    /** Échéances dues (statut à exécuter, date prévue atteinte) — source du job d'exécution. */
    List<MandatEcheance> findByStatutInAndDatePrevueLessThanEqual(Collection<StatutEcheance> statuts, LocalDate date);

    /** Échéances planifiées dont la date approche (fenêtre de préavis) — source du job de préavis. */
    List<MandatEcheance> findByStatutAndDatePrevueLessThanEqual(StatutEcheance statut, LocalDate date);

    /** Verrou PESSIMISTE d'une échéance : sérialise deux exécutions concurrentes → une seule agit. */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select e from MandatEcheance e where e.id = :id")
    Optional<MandatEcheance> verrouiller(@Param("id") UUID id);
}
