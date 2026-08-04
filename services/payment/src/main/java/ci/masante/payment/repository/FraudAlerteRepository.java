package ci.masante.payment.repository;

import ci.masante.payment.domain.model.FraudAlerte;
import ci.masante.payment.domain.model.StatutAlerteFraude;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;
import java.util.UUID;

public interface FraudAlerteRepository extends JpaRepository<FraudAlerte, UUID> {

    boolean existsByWalletIdAndStatut(UUID walletId, StatutAlerteFraude statut);

    List<FraudAlerte> findByWalletIdOrderByCreatedAtDesc(UUID walletId);

    List<FraudAlerte> findByStatutOrderByCreatedAtDesc(StatutAlerteFraude statut);
}
