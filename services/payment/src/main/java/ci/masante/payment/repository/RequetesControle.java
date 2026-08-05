package ci.masante.payment.repository;

import ci.masante.payment.domain.model.WalletEntry;
import ci.masante.payment.repository.projection.AgregatOperationProj;
import ci.masante.payment.repository.projection.ClawbackSourceProj;
import ci.masante.payment.repository.projection.ConsommationCampagneProj;
import ci.masante.payment.repository.projection.EncaissementProj;
import ci.masante.payment.repository.projection.FactureEtatProj;
import ci.masante.payment.repository.projection.SoldeWalletProj;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.Repository;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

/**
 * Requêtes AGRÉGÉES du contrôle d'intégrité (P5.3b-4), en SQL natif. Toutes bornées au cut-off T
 * (<b>created_at &lt; :avant</b>) : le contrôle observe un SNAPSHOT figé, pas la base « en direct »
 * (évite les faux positifs pendant l'écriture concurrente de paiements). LECTURE SEULE.
 *
 * <p>Les {@code ::bigint} sur les {@code sum()} forcent un type entier (Postgres promeut {@code sum}
 * sur {@code bigint} en {@code numeric}) → mapping fiable vers {@code long}.</p>
 */
public interface RequetesControle extends Repository<WalletEntry, UUID> {

    /** Somme de TOUTES les écritures du grand livre (doit valoir 0). */
    @Query(value = """
            select coalesce(sum(e.montant), 0)::bigint
            from wallet_entries e
            join wallet_operations o on o.id = e.operation_id
            where o.created_at < :avant
            """, nativeQuery = true)
    long sommeGlobale(@Param("avant") Instant avant);

    /** Opérations dont les écritures ne sont pas au nombre de 2 ou ne somment pas à 0. */
    @Query(value = """
            select o.id as "operationId", count(e.id)::bigint as "nombre",
                   coalesce(sum(e.montant), 0)::bigint as "somme"
            from wallet_operations o
            left join wallet_entries e on e.operation_id = o.id
            where o.created_at < :avant
            group by o.id
            having count(e.id) <> 2 or coalesce(sum(e.montant), 0) <> 0
            """, nativeQuery = true)
    List<AgregatOperationProj> operationsDesequilibrees(@Param("avant") Instant avant);

    /** Wallets à solde négatif (la règle tranche ensuite selon le type de titulaire). */
    @Query(value = """
            select w.id as "walletId", w.owner_type as "ownerType",
                   coalesce(sum(e.montant), 0)::bigint as "solde"
            from wallets w
            join wallet_entries e on e.wallet_id = w.id
            join wallet_operations o on o.id = e.operation_id
            where o.created_at < :avant
            group by w.id, w.owner_type
            having coalesce(sum(e.montant), 0) < 0
            """, nativeQuery = true)
    List<SoldeWalletProj> soldesNegatifs(@Param("avant") Instant avant);

    /** Factures actives (la règle vérifie la cohérence statut ↔ montant réglé / reste à payer). */
    @Query(value = """
            select f.numero as "numero", f.statut as "statut",
                   f.montant_regle as "montantRegle", f.reste_a_payer as "resteAPayer"
            from factures f
            where f.created_at < :avant and f.statut in ('EMISE', 'PARTIELLEMENT_PAYEE', 'PAYEE')
            """, nativeQuery = true)
    List<FactureEtatProj> etatsFactures(@Param("avant") Instant avant);

    /** Factures dont la somme des paiements passerelle SUCCESS excède le montant réglé. */
    @Query(value = """
            select f.numero as "numero", f.montant_regle as "montantRegle",
                   coalesce(sum(p.montant), 0)::bigint as "sommePasserelle"
            from factures f
            join payments p on p.facture_id = f.id and p.statut = 'SUCCESS' and p.created_at < :avant
            where f.created_at < :avant
            group by f.id, f.numero, f.montant_regle
            having coalesce(sum(p.montant), 0) > f.montant_regle
            """, nativeQuery = true)
    List<EncaissementProj> encaissementsNonRepercutes(@Param("avant") Instant avant);

    /** Cashback net consommé (crédits − clawbacks) par campagne à budget posé (la règle compare au budget). */
    @Query(value = """
            select c.code as "code", c.budget_total as "budget",
                   coalesce(sum(case when o.type = 'CASHBACK' then o.montant
                                     when o.type = 'CASHBACK_ANNULATION' then -o.montant
                                     else 0 end), 0)::bigint as "consomme"
            from cashback_campagnes c
            left join wallet_operations o on o.campagne_code = c.code and o.created_at < :avant
            where c.budget_total is not null
            group by c.code, c.budget_total
            """, nativeQuery = true)
    List<ConsommationCampagneProj> consommationsCampagnes(@Param("avant") Instant avant);

    /** Par opération source ayant subi une reprise : cashback d'origine vs clawback cumulé. */
    @Query(value = """
            select o.operation_source_id as "sourceId",
                   coalesce(sum(case when o.type = 'CASHBACK' then o.montant else 0 end), 0)::bigint as "cashback",
                   coalesce(sum(case when o.type = 'CASHBACK_ANNULATION' then o.montant else 0 end), 0)::bigint as "clawback"
            from wallet_operations o
            where o.operation_source_id is not null and o.created_at < :avant
            group by o.operation_source_id
            having coalesce(sum(case when o.type = 'CASHBACK_ANNULATION' then o.montant else 0 end), 0) > 0
            """, nativeQuery = true)
    List<ClawbackSourceProj> clawbacksParSource(@Param("avant") Instant avant);
}
