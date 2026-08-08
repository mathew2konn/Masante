package ci.masante.payment.repository;

import ci.masante.payment.domain.model.DecaissementReversement;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface DecaissementReversementRepository extends JpaRepository<DecaissementReversement, UUID> {

    /** Rejeu idempotent : retrouve la tentative déjà enregistrée pour cette clé (2e barrière après Redis). */
    Optional<DecaissementReversement> findByIdempotencyKey(String idempotencyKey);

    /** Tentatives d'un relevé, plus récentes d'abord. */
    List<DecaissementReversement> findByReleveIdOrderByCreeLeDesc(UUID releveId);
}
