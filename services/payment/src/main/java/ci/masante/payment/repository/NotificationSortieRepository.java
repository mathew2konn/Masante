package ci.masante.payment.repository;

import ci.masante.payment.domain.model.NotificationSortie;
import ci.masante.payment.domain.notification.StatutNotification;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface NotificationSortieRepository extends JpaRepository<NotificationSortie, UUID> {

    List<NotificationSortie> findTop200ByStatutOrderByCreeLeAsc(StatutNotification statut);

    List<NotificationSortie> findByDestinataireRefOrderByCreeLeDesc(String destinataireRef);

    /** Verrou pessimiste : deux relais concurrents ne livrent pas deux fois la même ligne. */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select n from NotificationSortie n where n.id = :id")
    Optional<NotificationSortie> verrouiller(@Param("id") UUID id);
}
