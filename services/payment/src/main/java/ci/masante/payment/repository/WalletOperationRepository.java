package ci.masante.payment.repository;

import ci.masante.payment.domain.model.TypeOperationWallet;
import ci.masante.payment.domain.model.WalletOperation;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.time.Instant;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface WalletOperationRepository extends JpaRepository<WalletOperation, UUID> {

    Optional<WalletOperation> findByIdempotencyKey(String idempotencyKey);

    /**
     * Nombre d'opérations sortantes UTILISATEUR abouties depuis un instant (vélocité, §6.4). Exclut
     * les reprises de cashback (source = wallet mais non imputables à l'utilisateur).
     */
    @Query("select count(o) from WalletOperation o where o.sourceWalletId = :walletId "
            + "and o.createdAt >= :depuis and o.type in ("
            + "ci.masante.payment.domain.model.TypeOperationWallet.DEBIT, "
            + "ci.masante.payment.domain.model.TypeOperationWallet.TRANSFERT, "
            + "ci.masante.payment.domain.model.TypeOperationWallet.PAIEMENT_FACTURE)")
    int compteSortantesDepuis(@Param("walletId") UUID walletId, @Param("depuis") Instant depuis);

    // --- cashback / rewards -------------------------------------------------------------------

    /** Somme reçue par un wallet pour un type d'opération (sous-solde cashback/bonus, dérivé). */
    @Query("select coalesce(sum(o.montant), 0) from WalletOperation o "
            + "where o.destWalletId = :walletId and o.type = :type")
    long sommeRecueParType(@Param("walletId") UUID walletId, @Param("type") TypeOperationWallet type);

    /** Somme des reprises (clawback) sortant d'un wallet (pour le sous-solde net). */
    @Query("select coalesce(sum(o.montant), 0) from WalletOperation o where o.sourceWalletId = :walletId "
            + "and o.type = ci.masante.payment.domain.model.TypeOperationWallet.CASHBACK_ANNULATION")
    long sommeClawbackDe(@Param("walletId") UUID walletId);

    /** Budget consommé d'une campagne = somme des cashbacks accordés (référence = code). */
    @Query("select coalesce(sum(o.montant), 0) from WalletOperation o "
            + "where o.type = ci.masante.payment.domain.model.TypeOperationWallet.CASHBACK "
            + "and o.campagneCode = :code")
    long sommeCashbackCampagne(@Param("code") String code);

    /** Cashback déjà accordé à un wallet pour une campagne (plafond par wallet). */
    @Query("select coalesce(sum(o.montant), 0) from WalletOperation o "
            + "where o.type = ci.masante.payment.domain.model.TypeOperationWallet.CASHBACK "
            + "and o.campagneCode = :code and o.destWalletId = :walletId")
    long sommeCashbackCampagneWallet(@Param("code") String code, @Param("walletId") UUID walletId);

    /**
     * Cashback accordé à un wallet pour une campagne dont l'OPÉRATION SOURCE tombe dans [debut, fin[
     * (plafond par wallet et par jour, keyé sur la date de l'op source — pas l'heure d'appel).
     */
    @Query("select coalesce(sum(o.montant), 0) from WalletOperation o, WalletOperation src "
            + "where o.type = ci.masante.payment.domain.model.TypeOperationWallet.CASHBACK "
            + "and o.campagneCode = :code and o.destWalletId = :walletId "
            + "and o.operationSourceId = src.id and src.createdAt >= :debut and src.createdAt < :fin")
    long sommeCashbackCampagneWalletJour(@Param("code") String code, @Param("walletId") UUID walletId,
                                         @Param("debut") Instant debut, @Param("fin") Instant fin);

    /** Cashback accordé sur une opération source (au plus un — clé idempotente dérivée). */
    @Query("select o from WalletOperation o "
            + "where o.type = ci.masante.payment.domain.model.TypeOperationWallet.CASHBACK "
            + "and o.operationSourceId = :sourceId")
    List<WalletOperation> cashbacksDeSource(@Param("sourceId") UUID sourceId);

    /** Cumul déjà repris (clawback) pour une opération source. */
    @Query("select coalesce(sum(o.montant), 0) from WalletOperation o "
            + "where o.type = ci.masante.payment.domain.model.TypeOperationWallet.CASHBACK_ANNULATION "
            + "and o.operationSourceId = :sourceId")
    long sommeClawbackSource(@Param("sourceId") UUID sourceId);
}
