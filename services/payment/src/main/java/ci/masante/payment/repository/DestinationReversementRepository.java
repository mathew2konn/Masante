package ci.masante.payment.repository;

import ci.masante.payment.domain.model.DestinationReversement;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface DestinationReversementRepository extends JpaRepository<DestinationReversement, UUID> {

    /** Destination active (non clôturée) d'un établissement. */
    Optional<DestinationReversement> findByEtablissementRefAndValideAuIsNull(String etablissementRef);

    List<DestinationReversement> findByEtablissementRefOrderByValideDuDesc(String etablissementRef);
}
