package ci.masante.payment.repository;

import ci.masante.payment.domain.model.ReversementCompteur;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Modifying;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Optional;
import java.util.UUID;

public interface ReversementCompteurRepository extends JpaRepository<ReversementCompteur, UUID> {

    /**
     * Crée la ligne de compteur si absente (idempotent, concurrent-safe). Nécessaire pour avoir une
     * ligne à verrouiller AVANT la lecture de l'assiette (ADR-016 §4, ordre de verrou).
     */
    @Modifying
    @Query(value = "insert into reversement_compteur (id, etablissement_ref, exercice, dernier) "
            + "values (gen_random_uuid(), :etab, :exercice, 0) "
            + "on conflict (etablissement_ref, exercice) do nothing", nativeQuery = true)
    void creerSiAbsent(@Param("etab") String etab, @Param("exercice") int exercice);

    /** Compteur verrouillé en écriture : sérialise calcul + numérotation + chaînage de report. */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select c from ReversementCompteur c where c.etablissementRef = :etab and c.exercice = :exercice")
    Optional<ReversementCompteur> trouverVerrouille(@Param("etab") String etab, @Param("exercice") int exercice);
}
