package ci.masante.payment.repository;

import ci.masante.payment.domain.model.ReversementReleve;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import ci.masante.payment.domain.model.ReversementStatut;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

public interface ReversementReleveRepository extends JpaRepository<ReversementReleve, UUID> {

    List<ReversementReleve> findByEtablissementRefOrderByCalculeAAsc(String etablissementRef);

    /** Nombre de tentatives déjà enregistrées (tous statuts) pour cette période → tentative suivante. */
    long countByEtablissementRefAndPeriodeDebutAndPeriodeFin(String etab, Instant debut, Instant fin);

    /** Un relevé vivant couvre-t-il déjà exactement cette période ? (conflit → 409). */
    boolean existsByEtablissementRefAndPeriodeDebutAndPeriodeFinAndStatutNot(
            String etab, Instant debut, Instant fin, ReversementStatut statut);

    List<ReversementReleve> findByEtablissementRefAndExerciceOrderByCalculeAAsc(String etablissementRef, int exercice);

    /**
     * Queue de la chaîne : le(s) relevé(s) VIVANT(s) sans successeur vivant. En pratique 0 (aucun
     * relevé) ou 1 (le dernier maillon). Son {@code soldeReporte} devient le {@code reportAnterieur}
     * du prochain relevé (ADR-016 §2).
     */
    @Query("select r from ReversementReleve r "
            + "where r.etablissementRef = :etab "
            + "and r.statut <> ci.masante.payment.domain.model.ReversementStatut.ANNULE "
            + "and not exists (select 1 from ReversementReleve s where s.relevePrecedentId = r.id "
            + "and s.statut <> ci.masante.payment.domain.model.ReversementStatut.ANNULE)")
    List<ReversementReleve> trouverQueue(@Param("etab") String etab);

    /** Nombre de successeurs vivants d'un relevé : &gt; 0 interdit l'annulation (ADR-016 §2). */
    @Query("select count(s) from ReversementReleve s where s.relevePrecedentId = :id "
            + "and s.statut <> ci.masante.payment.domain.model.ReversementStatut.ANNULE")
    long compterSuccesseursVivants(@Param("id") UUID id);
}
