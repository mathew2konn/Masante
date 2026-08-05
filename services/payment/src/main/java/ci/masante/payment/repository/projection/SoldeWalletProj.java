package ci.masante.payment.repository.projection;

import java.util.UUID;

/** Solde (= somme des écritures) d'un wallet, avec son type de titulaire (pour la règle de dette). */
public interface SoldeWalletProj {
    UUID getWalletId();

    String getOwnerType();

    long getSolde();
}
