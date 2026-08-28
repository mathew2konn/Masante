package ci.masante.payment.repository;

import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.StatutGeniusPay;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.Collection;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface GeniusPayTransactionRepository extends JpaRepository<GeniusPayTransaction, UUID> {

    Optional<GeniusPayTransaction> findByReferenceInterne(String referenceInterne);

    Optional<GeniusPayTransaction> findByReferencePasserelle(String referencePasserelle);

    Optional<GeniusPayTransaction> findByPaiementId(UUID paiementId);

    /**
     * Verrou pessimiste pour l'application d'un événement (§8.4). Deux renvois concurrents du même
     * webhook — GeniusPay réessaie cinq fois — se sérialisent ici plutôt que de s'écraser.
     */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select t from GeniusPayTransaction t where t.id = :id")
    Optional<GeniusPayTransaction> verrouiller(@Param("id") UUID id);

    /** Transactions d'une facture, tous statuts — la réutilisation d'un checkout se décide dessus. */
    List<GeniusPayTransaction> findByFactureId(UUID factureId);

    /** Candidates à la réconciliation : non terminales, référence connue, pas trop anciennes. */
    List<GeniusPayTransaction> findByStatutGeniusPayInAndReferencePasserelleIsNotNullAndInitieeLeBetween(
            Collection<StatutGeniusPay> statuts, Instant depuis, Instant jusqua);

    /** Candidates au balayage §7.4.b : incertaines, sans référence, au-delà du délai de levée. */
    List<GeniusPayTransaction> findByStatutGeniusPayAndReferencePasserelleIsNullAndInitieeLeBefore(
            StatutGeniusPay statut, Instant avant);
}
