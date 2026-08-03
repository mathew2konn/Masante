package ci.masante.payment.repository;

import ci.masante.payment.domain.model.Paiement;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;
import java.util.UUID;

public interface PaiementRepository extends JpaRepository<Paiement, UUID> {

    /** Rejeu idempotent : un paiement au plus par clé d'idempotence (contrainte unique en base). */
    Optional<Paiement> findByIdempotencyKey(String idempotencyKey);
}
