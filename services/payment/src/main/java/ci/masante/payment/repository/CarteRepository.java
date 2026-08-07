package ci.masante.payment.repository;

import ci.masante.payment.domain.model.Carte;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface CarteRepository extends JpaRepository<Carte, UUID> {

    /** Vault de l'utilisateur (cartes non supprimées). */
    List<Carte> findByUtilisateurRefAndSupprimeLeIsNullOrderByCreeLeDesc(String utilisateurRef);

    /** Contrôle de propriété pour la suppression (soft delete). */
    Optional<Carte> findByIdAndUtilisateurRefAndSupprimeLeIsNull(UUID id, String utilisateurRef);

    /** Détection de doublon par empreinte (fingerprint PSP) au sein d'un utilisateur. */
    Optional<Carte> findByUtilisateurRefAndEmpreinteAndSupprimeLeIsNull(String utilisateurRef, String empreinte);

    Optional<Carte> findByPspAndToken(String psp, String token);
}
