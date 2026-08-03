package ci.masante.payment.repository;

import ci.masante.payment.domain.model.OwnerTypeWallet;
import ci.masante.payment.domain.model.Wallet;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Optional;
import java.util.UUID;

public interface WalletRepository extends JpaRepository<Wallet, UUID> {

    Optional<Wallet> findByOwnerRefAndOwnerTypeAndDevise(String ownerRef, OwnerTypeWallet ownerType, String devise);

    /** Wallet verrouillé en écriture : sérialise les débits concurrents (contrôle de solde fiable). */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select w from Wallet w where w.id = :id")
    Optional<Wallet> findByIdVerrouille(@Param("id") UUID id);
}
