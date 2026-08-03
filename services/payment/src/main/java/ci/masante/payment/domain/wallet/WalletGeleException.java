package ci.masante.payment.domain.wallet;

import ci.masante.payment.domain.model.WalletStatut;

/** Débit/transfert refusé car le wallet n'est pas ACTIF (CDC_06 §6.4). → 409. */
public class WalletGeleException extends RuntimeException {

    public WalletGeleException(WalletStatut statut) {
        super("Opération refusée : le portefeuille est " + statut + ".");
    }
}
