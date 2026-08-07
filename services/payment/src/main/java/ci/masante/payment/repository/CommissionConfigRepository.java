package ci.masante.payment.repository;

import ci.masante.payment.domain.model.CommissionConfig;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;
import java.util.UUID;

public interface CommissionConfigRepository extends JpaRepository<CommissionConfig, UUID> {

    /** Taux ouvert (non clôturé) d'un établissement précis. */
    Optional<CommissionConfig> findByEtablissementRefAndValideAuIsNull(String etablissementRef);

    /** Taux ouvert par défaut de la plateforme (etablissement_ref NULL). */
    Optional<CommissionConfig> findByEtablissementRefIsNullAndValideAuIsNull();
}
