package ci.masante.payment.repository;

import ci.masante.payment.domain.model.Facture;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface FactureRepository extends JpaRepository<Facture, UUID> {

    /** Toutes les versions d'une lignée (par id d'origine), triées par numéro de version. */
    List<Facture> findByOrigineFactureIdOrderByVersionNumeroAsc(UUID origineFactureId);
}
