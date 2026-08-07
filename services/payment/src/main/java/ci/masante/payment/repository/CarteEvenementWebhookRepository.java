package ci.masante.payment.repository;

import ci.masante.payment.domain.model.CarteEvenementWebhook;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.UUID;

public interface CarteEvenementWebhookRepository extends JpaRepository<CarteEvenementWebhook, UUID> {

    /** Pré-contrôle de déduplication (la contrainte UNIQUE(psp, evenement_id) reste l'autorité). */
    boolean existsByPspAndEvenementId(String psp, String evenementId);
}
