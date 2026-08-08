package ci.masante.payment.repository;

import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.repository.projection.LigneRapprochementProj;
import ci.masante.payment.repository.projection.PieceNonReverseeProj;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.Repository;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

/**
 * Requêtes du rapprochement à DEUX sources « factures ↔ reversements » (P5.5c, CDC_06 §11), en SQL
 * natif. LECTURE SEULE. Bornées au snapshot : la complétude A→B utilise le <b>seuil de grâce</b>
 * ({@code < :grace}) ; l'intégrité B→A utilise le <b>cut-off T</b> ({@code created_at < :avant}). Les
 * {@code not exists} portent sur les lignes ACTIVES (I1) : une pièce libérée par l'annulation d'un
 * relevé redevient « due ». C'est bien une confrontation entre deux sous-systèmes distincts
 * (facturation ⇄ reversement), pas la base contre elle-même (ADR-014).
 */
public interface RequetesRapprochement extends Repository<Facture, UUID> {

    // ── Source A → B (complétude) : pièces dues jamais reversées ────────────────────────────────

    /**
     * Factures PAYEE au réglé &gt; 0, soldées avant le seuil de grâce, absentes de toute ligne FACTURE
     * active. Balayage GLOBAL (tous établissements) — pas de filtre par établissement, à la différence
     * de l'assiette de calcul d'un relevé.
     */
    @Query(value = """
            select f.numero as "reference", 'FACTURE' as "typePiece",
                   f.soldee_a as "dateeA", f.montant_regle as "montant"
            from factures f
            where f.statut = 'PAYEE' and f.montant_regle > 0
              and f.soldee_a is not null and f.soldee_a < :grace
              and not exists (select 1 from reversement_releve_lignes l
                              where l.facture_id = f.id and l.releve_actif = true and l.type_ligne = 'FACTURE')
            """, nativeQuery = true)
    List<PieceNonReverseeProj> facturesNonReversees(@Param("grace") Instant grace);

    /**
     * Remboursements carte REUSSI (établissement figé), datés avant le seuil de grâce, absents de toute
     * ligne REMBOURSEMENT active.
     */
    @Query(value = """
            select r.ref_passerelle_remboursement as "reference", 'REMBOURSEMENT' as "typePiece",
                   r.cree_le as "dateeA", r.montant as "montant"
            from carte_remboursements r
            where r.statut = 'REUSSI' and r.etablissement_ref is not null and r.cree_le < :grace
              and not exists (select 1 from reversement_releve_lignes l
                              where l.remboursement_id = r.id and l.releve_actif = true and l.type_ligne = 'REMBOURSEMENT')
            """, nativeQuery = true)
    List<PieceNonReverseeProj> remboursementsNonReverses(@Param("grace") Instant grace);

    // ── Source B → A (intégrité + montant) : lignes actives confrontées à leur pièce ─────────────

    /** Lignes FACTURE actives (créées avant T) et leur facture courante (LEFT JOIN → orphelin si absente). */
    @Query(value = """
            select l.piece_reference as "reference", l.montant_regle_impute as "montantImpute",
                   f.statut as "pieceStatut", f.etablissement_ref as "pieceEtab",
                   rel.etablissement_ref as "releveEtab", f.montant_regle as "montantCourant"
            from reversement_releve_lignes l
            join reversement_releves rel on rel.id = l.releve_id
            left join factures f on f.id = l.facture_id
            where l.releve_actif = true and l.type_ligne = 'FACTURE' and l.created_at < :avant
            """, nativeQuery = true)
    List<LigneRapprochementProj> lignesFactureActives(@Param("avant") Instant avant);

    /** Lignes REMBOURSEMENT actives (créées avant T) et leur remboursement courant (LEFT JOIN → orphelin). */
    @Query(value = """
            select l.piece_reference as "reference", l.montant_rembourse_impute as "montantImpute",
                   r.statut as "pieceStatut", r.etablissement_ref as "pieceEtab",
                   rel.etablissement_ref as "releveEtab", r.montant as "montantCourant"
            from reversement_releve_lignes l
            join reversement_releves rel on rel.id = l.releve_id
            left join carte_remboursements r on r.id = l.remboursement_id
            where l.releve_actif = true and l.type_ligne = 'REMBOURSEMENT' and l.created_at < :avant
            """, nativeQuery = true)
    List<LigneRapprochementProj> lignesRemboursementActives(@Param("avant") Instant avant);
}
