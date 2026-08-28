package ci.masante.payment.repository;

import ci.masante.payment.domain.model.IdentifiantMarchand;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;
import java.util.UUID;

public interface IdentifiantMarchandRepository extends JpaRepository<IdentifiantMarchand, UUID> {

    /**
     * Résolution par le slug de l'URL de rappel. C'est le SEUL chemin de sélection du secret webhook :
     * il n'existe volontairement aucune méthode « chercher tous les secrets », qui rendrait l'essai en
     * cascade possible — coût O(n) et oracle de temps offert à l'attaquant (arbitrage §3).
     */
    Optional<IdentifiantMarchand> findBySlugAndActifIsTrue(String slug);

    Optional<IdentifiantMarchand> findByEtablissementRefAndPspAndActifIsTrue(String etablissementRef, String psp);
}
