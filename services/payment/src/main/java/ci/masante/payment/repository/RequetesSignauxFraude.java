package ci.masante.payment.repository;

import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.repository.projection.ActePrincipalProj;
import ci.masante.payment.repository.projection.SignauxFactureProj;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.Repository;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

/**
 * Requêtes d'EXTRACTION des signaux de facturation pour la détection de fraude (CDC_05, incrément A),
 * en SQL natif. <b>LECTURE SEULE</b> : ces requêtes projettent et agrègent les données du domaine
 * paiement (factures, lignes, remboursements carte, wallet) ; elles ne décident RIEN et n'écrivent
 * RIEN. Le jugement de fraude vit dans le microservice fraude (Python), jamais ici.
 *
 * <p>Toutes les fenêtres sont bornées par un cut-off {@code T} (paramètre {@code asOf} du service,
 * défaut = maintenant) pour la reproductibilité : on n'examine que ce qui existait à T. Les bornes
 * basses (30 j / 7 j / 24 h / 1 h) et les bornes de journée sont calculées en Java et passées en
 * paramètres — pas d'arithmétique d'intervalle SQL, plus simple à raisonner et à tester.</p>
 *
 * <p>Le couplage au schéma reste chez son propriétaire (le service paiement) : la fraude ne lit JAMAIS
 * ces tables (ADR-014). Elle consomme le DTO exposé par le controller.</p>
 */
public interface RequetesSignauxFraude extends Repository<Facture, UUID> {

    /** Champs de base d'une facture par son numéro (identifiant fonctionnel). Vide si inexistante. */
    @Query(value = """
            select f.id as "id", f.numero as "reference", f.etablissement_ref as "etablissementRef",
                   f.patient_ref as "patientRef", f.montant_ttc as "montantTtc",
                   f.montant_couvert as "montantCouvert", f.reste_a_payer as "resteAPayer",
                   f.created_at as "createdAt"
            from factures f
            where f.numero = :numero
            """, nativeQuery = true)
    Optional<SignauxFactureProj> factureParNumero(@Param("numero") String numero);

    /** Ligne d'acte au TTC le plus élevé de la facture (l'acte « principal »). Vide si aucune ligne. */
    @Query(value = """
            select fl.libelle as "libelle", fl.montant_ttc as "montant"
            from facture_lignes fl
            where fl.facture_id = :factureId
            order by fl.montant_ttc desc
            limit 1
            """, nativeQuery = true)
    Optional<ActePrincipalProj> actePrincipal(@Param("factureId") UUID factureId);

    /**
     * Montant TTC moyen historique d'un acte (même libellé), sur toute la base jusqu'au cut-off T. Sert
     * de référentiel à la règle « montant aberrant ». La facture courante y est incluse → au moins sa
     * propre valeur, jamais 0 → ratio ≈ 1 pour un acte isolé (pas de faux positif « aberrant »).
     */
    @Query(value = """
            select coalesce(round(avg(fl.montant_ttc)), 0)::bigint
            from facture_lignes fl
            join factures f on f.id = fl.facture_id
            where fl.libelle = :libelle and f.created_at <= :t
            """, nativeQuery = true)
    long moyenneReferenceActe(@Param("libelle") String libelle, @Param("t") Instant t);

    /** Nombre de factures d'un établissement dans la fenêtre [depuis, T] (vélocité de facturation). */
    @Query(value = """
            select count(*)
            from factures f
            where f.etablissement_ref = :etab and f.created_at >= :depuis and f.created_at <= :t
            """, nativeQuery = true)
    long nbFacturesEtablissement(@Param("etab") String etab,
                                 @Param("depuis") Instant depuis, @Param("t") Instant t);

    /**
     * Nombre de lignes d'acte identiques (même libellé) facturées par le même établissement sur la
     * journée du cut-off [jourDebut, jourFin[ (répétition d'actes dans la journée).
     */
    @Query(value = """
            select count(*)
            from facture_lignes fl
            join factures f on f.id = fl.facture_id
            where f.etablissement_ref = :etab and fl.libelle = :libelle
              and f.created_at >= :jourDebut and f.created_at < :jourFin
            """, nativeQuery = true)
    long nbActesIdentiquesJour(@Param("etab") String etab, @Param("libelle") String libelle,
                               @Param("jourDebut") Instant jourDebut, @Param("jourFin") Instant jourFin);

    /**
     * Nombre de remboursements carte d'un patient dans la fenêtre [depuis, T] (remboursements en rafale).
     * On compte toutes les tentatives (tous statuts) : une rafale de demandes est en soi un signal. Le
     * patient est atteint par la chaîne remboursement → transaction carte → paiement.
     */
    @Query(value = """
            select count(*)
            from carte_remboursements r
            join carte_transactions ct on ct.id = r.carte_transaction_id
            join payments p on p.id = ct.paiement_id
            where p.patient_ref = :patient and r.cree_le >= :depuis and r.cree_le <= :t
            """, nativeQuery = true)
    long nbRemboursementsCarte(@Param("patient") String patient,
                               @Param("depuis") Instant depuis, @Param("t") Instant t);

    /**
     * Cumul (somme des montants bruts) des opérations wallet touchant le portefeuille PATIENT (en source
     * OU en destination) dans la fenêtre [depuis, T]. Sous-requêtes {@code in (...)} → chaque opération
     * comptée une seule fois même si les deux jambes appartiennent au même patient.
     */
    @Query(value = """
            select coalesce(sum(o.montant), 0)::bigint
            from wallet_operations o
            where o.created_at >= :depuis and o.created_at <= :t
              and (o.source_wallet_id in (select w.id from wallets w
                                          where w.owner_ref = :patient and w.owner_type = 'PATIENT')
                or o.dest_wallet_id in (select w.id from wallets w
                                        where w.owner_ref = :patient and w.owner_type = 'PATIENT'))
            """, nativeQuery = true)
    long cumulWallet(@Param("patient") String patient,
                     @Param("depuis") Instant depuis, @Param("t") Instant t);

    /** Nombre d'opérations wallet du patient dans la fenêtre [depuis, T] (vélocité d'opérations). */
    @Query(value = """
            select count(distinct o.id)
            from wallet_operations o
            where o.created_at >= :depuis and o.created_at <= :t
              and (o.source_wallet_id in (select w.id from wallets w
                                          where w.owner_ref = :patient and w.owner_type = 'PATIENT')
                or o.dest_wallet_id in (select w.id from wallets w
                                        where w.owner_ref = :patient and w.owner_type = 'PATIENT'))
            """, nativeQuery = true)
    long nbOpsWallet(@Param("patient") String patient,
                     @Param("depuis") Instant depuis, @Param("t") Instant t);

    /**
     * Numéros des factures créées dans la fenêtre [depuis, T] (sélection du lot à évaluer par le routage
     * de fraude B1). Bornée par {@code :limite} pour ne jamais balayer toute la base d'un coup.
     */
    @Query(value = """
            select f.numero
            from factures f
            where f.created_at >= :depuis and f.created_at <= :t
            order by f.created_at desc
            limit :limite
            """, nativeQuery = true)
    List<String> numerosFacturesEntre(@Param("depuis") Instant depuis, @Param("t") Instant t,
                                      @Param("limite") int limite);

    /** Instant de confirmation du règlement de la facture (paiement SUCCESS le plus ancien). Vide si non réglée. */
    @Query(value = """
            select p.confirmed_at
            from payments p
            where p.facture_id = :factureId and p.statut = 'SUCCESS' and p.confirmed_at is not null
            order by p.confirmed_at asc
            limit 1
            """, nativeQuery = true)
    Optional<Instant> confirmationReglement(@Param("factureId") UUID factureId);
}
