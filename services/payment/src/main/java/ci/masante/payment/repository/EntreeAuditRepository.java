package ci.masante.payment.repository;

import ci.masante.payment.domain.model.EntreeAudit;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface EntreeAuditRepository extends JpaRepository<EntreeAudit, UUID> {

    /** Nombre d'événements d'un type pour une référence depuis un instant (signal fraude, §6.4). */
    @Query("select count(e) from EntreeAudit e where e.refType = :refType and e.refId = :refId "
            + "and e.evenement = :evenement and e.createdAt >= :depuis")
    int compteEvenementDepuis(@Param("refType") String refType, @Param("refId") String refId,
                              @Param("evenement") String evenement, @Param("depuis") Instant depuis);

    /**
     * Dernière entrée de la chaîne, verrouillée en écriture (PESSIMISTIC_WRITE) : sérialise les
     * ajouts concurrents pour que la séquence et le chaînage de hash restent cohérents.
     */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select e from EntreeAudit e where e.sequence = (select max(x.sequence) from EntreeAudit x)")
    Optional<EntreeAudit> findDerniereVerrouillee();

    List<EntreeAudit> findByRefTypeAndRefIdOrderBySequenceAsc(String refType, String refId);

    List<EntreeAudit> findByRefTypeAndRefIdOrderBySequenceDesc(String refType, String refId);

    List<EntreeAudit> findAllByOrderBySequenceAsc();
}
