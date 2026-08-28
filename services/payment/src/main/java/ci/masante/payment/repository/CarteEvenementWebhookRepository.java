package ci.masante.payment.repository;

import ci.masante.payment.domain.model.CarteEvenementWebhook;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface CarteEvenementWebhookRepository extends JpaRepository<CarteEvenementWebhook, UUID> {

    /** Pré-contrôle de déduplication (la contrainte UNIQUE(psp, evenement_id) reste l'autorité). */
    boolean existsByPspAndEvenementId(String psp, String evenementId);

    Optional<CarteEvenementWebhook> findByPspAndEvenementId(String psp, String evenementId);

    /**
     * File d'attente du traitement hors requête (lot 7, §8.3). Le contrôleur ENREGISTRE et rend la
     * main en moins de 500 ms ; c'est ce relais planifié qui applique. Aucun {@code @Async} n'a été
     * ajouté : le motif est celui de l'Outbox de notification (P5.4c), déjà en place et éprouvé.
     */
    List<CarteEvenementWebhook> findTop50ByPspAndStatutTraitementOrderByRecuLeAsc(String psp, String statut);
}
