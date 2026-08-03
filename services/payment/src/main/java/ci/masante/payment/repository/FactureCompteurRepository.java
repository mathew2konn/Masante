package ci.masante.payment.repository;

import ci.masante.payment.domain.model.FactureCompteur;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Optional;
import java.util.UUID;

public interface FactureCompteurRepository extends JpaRepository<FactureCompteur, UUID> {

    /** Compteur verrouillé en écriture : sérialise l'attribution des numéros (établissement, exercice). */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select c from FactureCompteur c where c.etablissementRef = :etab and c.exercice = :exercice")
    Optional<FactureCompteur> trouverVerrouille(@Param("etab") String etab, @Param("exercice") int exercice);
}
