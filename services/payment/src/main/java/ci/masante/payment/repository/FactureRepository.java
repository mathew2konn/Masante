package ci.masante.payment.repository;

import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.domain.reversement.EncaissementImputable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

public interface FactureRepository extends JpaRepository<Facture, UUID> {

    /** Toutes les versions d'une lignée (par id d'origine), triées par numéro de version. */
    List<Facture> findByOrigineFactureIdOrderByVersionNumeroAsc(UUID origineFactureId);

    /**
     * Assiette des encaissements imputables à un établissement (§11). Factures PAYEE, {@code
     * montantRegle > 0}, soldées AVANT {@code avant} (borne haute = fin de période ; PAS de borne basse
     * → les pièces arrivées tardivement sont reprises automatiquement, ADR-016 §2), et NON déjà
     * imputées sur un relevé actif (I1). C'est l'index I1, pas la fenêtre, qui interdit le double
     * paiement.
     */
    @Query("select new ci.masante.payment.domain.reversement.EncaissementImputable(f.id, f.numero, f.soldeeA, f.montantRegle) "
            + "from Facture f "
            + "where f.etablissementRef = :etab "
            + "and f.statut = ci.masante.payment.domain.model.FactureStatut.PAYEE "
            + "and f.montantRegle > 0 and f.soldeeA is not null and f.soldeeA < :avant "
            + "and not exists (select 1 from LigneReversement l where l.factureId = f.id and l.releveActif = true "
            + "and l.typeLigne = ci.masante.payment.domain.model.TypeLigneReversement.FACTURE) "
            + "order by f.soldeeA asc")
    List<EncaissementImputable> encaissementsImputables(@Param("etab") String etab, @Param("avant") Instant avant);
}
