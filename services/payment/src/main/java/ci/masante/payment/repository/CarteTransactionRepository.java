package ci.masante.payment.repository;

import ci.masante.payment.domain.carte.StatutCarte;
import ci.masante.payment.domain.model.CarteTransaction;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface CarteTransactionRepository extends JpaRepository<CarteTransaction, UUID> {

    Optional<CarteTransaction> findByPaiementId(UUID paiementId);

    Optional<CarteTransaction> findByPspAndRefPasserelle(String psp, String refPasserelle);

    /**
     * Verrou PESSIMISTE ({@code SELECT … FOR UPDATE}) pour sérialiser la finalisation / le webhook :
     * deux appels concurrents ne peuvent pas produire deux captures (§7.2).
     */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select t from CarteTransaction t where t.id = :id")
    Optional<CarteTransaction> verrouiller(@Param("id") UUID id);

    /** Défis/redirections en attente dont le délai est dépassé (job d'expiration §8). */
    List<CarteTransaction> findByStatutCarteAndChallengeExpireLeBefore(StatutCarte statut, Instant instant);

    /** Autorisations non capturées dont le délai est dépassé (job d'expiration §8 ; capture différée future). */
    List<CarteTransaction> findByStatutCarteAndAutorisationExpireLeBefore(StatutCarte statut, Instant instant);

    /** Transactions d'un PSP créées dans l'intervalle [début, fin) — source LOCALE de la réconciliation. */
    List<CarteTransaction> findByPspAndCreeLeGreaterThanEqualAndCreeLeLessThan(String psp, Instant debut, Instant fin);
}
