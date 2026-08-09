package ci.masante.payment.repository;

import ci.masante.payment.domain.mandat.MandatStatut;
import ci.masante.payment.domain.model.Mandat;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface MandatRepository extends JpaRepository<Mandat, UUID> {

    Optional<Mandat> findByIdempotencyKey(String idempotencyKey);

    List<Mandat> findByUtilisateurRefOrderByCreeLeDesc(String utilisateurRef);

    /** Mandats actifs dont la date de fin est dépassée (job d'expiration §5.4). */
    List<Mandat> findByStatutAndDateFinBefore(MandatStatut statut, LocalDate date);

    /**
     * Verrou PESSIMISTE ({@code SELECT … FOR UPDATE}) : sérialise l'exécution d'une échéance avec
     * suspension/annulation/reprise concurrentes (une seule avance à la fois).
     */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select m from Mandat m where m.id = :id")
    Optional<Mandat> verrouiller(@Param("id") UUID id);
}
