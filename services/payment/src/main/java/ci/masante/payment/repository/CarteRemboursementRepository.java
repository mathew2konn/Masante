package ci.masante.payment.repository;

import ci.masante.payment.domain.model.CarteRemboursement;
import ci.masante.payment.domain.reversement.RemboursementImputable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface CarteRemboursementRepository extends JpaRepository<CarteRemboursement, UUID> {

    List<CarteRemboursement> findByCarteTransactionId(UUID carteTransactionId);

    Optional<CarteRemboursement> findByPspAndRefPasserelleRemboursement(String psp, String ref);

    /**
     * Assiette des remboursements imputables à un établissement (§11). REUSSI, établissement figé,
     * {@code cree_le} (date d'imputation immuable) AVANT {@code avant} (borne haute seule → rattrapage),
     * et NON déjà imputé sur un relevé actif (I1).
     */
    @Query("select new ci.masante.payment.domain.reversement.RemboursementImputable(r.id, r.refPasserelleRemboursement, r.creeLe, r.montant) "
            + "from CarteRemboursement r "
            + "where r.etablissementRef = :etab and r.statut = 'REUSSI' and r.creeLe < :avant "
            + "and not exists (select 1 from LigneReversement l where l.remboursementId = r.id and l.releveActif = true "
            + "and l.typeLigne = ci.masante.payment.domain.model.TypeLigneReversement.REMBOURSEMENT) "
            + "order by r.creeLe asc")
    List<RemboursementImputable> remboursementsImputables(@Param("etab") String etab, @Param("avant") Instant avant);
}
