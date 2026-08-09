package ci.masante.payment.repository;

import ci.masante.payment.domain.model.AlerteFraudeIa;
import ci.masante.payment.domain.model.StatutAlerteFraudeIa;
import org.springframework.data.jpa.repository.JpaRepository;

import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface AlerteFraudeIaRepository extends JpaRepository<AlerteFraudeIa, UUID> {

    /** Alerte existante pour une facture et une journée de run (idempotence du scan). */
    Optional<AlerteFraudeIa> findByFactureRefAndDateRapport(String factureRef, LocalDate dateRapport);

    List<AlerteFraudeIa> findTop200ByOrderByCreatedAtDesc();

    List<AlerteFraudeIa> findTop200ByStatutOrderByCreatedAtDesc(StatutAlerteFraudeIa statut);
}
