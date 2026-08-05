package ci.masante.payment.repository;

import ci.masante.payment.domain.model.ControleRun;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Modifying;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface ControleRunRepository extends JpaRepository<ControleRun, UUID> {

    Optional<ControleRun> findByJournee(LocalDate journee);

    List<ControleRun> findTop60ByOrderByJourneeDesc();

    /**
     * Purge le run d'une journée (idempotence : un rejeu remplace le verdict). Suppression immédiate
     * (bulk) ; les écarts partent par la contrainte FK {@code ON DELETE CASCADE} de {@code controle_ecarts}.
     */
    @Modifying
    @Query(value = "delete from controle_runs where journee = :journee", nativeQuery = true)
    void supprimerParJournee(@Param("journee") LocalDate journee);
}
