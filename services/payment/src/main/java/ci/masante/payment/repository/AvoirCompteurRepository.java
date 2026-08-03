package ci.masante.payment.repository;

import ci.masante.payment.domain.model.AvoirCompteur;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Optional;
import java.util.UUID;

public interface AvoirCompteurRepository extends JpaRepository<AvoirCompteur, UUID> {

    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select c from AvoirCompteur c where c.etablissementRef = :etab and c.exercice = :exercice")
    Optional<AvoirCompteur> trouverVerrouille(@Param("etab") String etab, @Param("exercice") int exercice);
}
