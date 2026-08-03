package ci.masante.payment.domain.wallet;

import ci.masante.payment.domain.model.WalletStatut;

/**
 * Règles métier du wallet (CDC_06 §6) — <b>frontière</b> : contrôle de suffisance et d'état côté
 * backend seul. Classe pure (sans Spring), testable en unitaire.
 */
public final class ReglesWallet {

    private ReglesWallet() {
    }

    public static void verifierMontant(long montant) {
        if (montant <= 0) {
            throw new OperationWalletInvalideException("Le montant doit être strictement positif.");
        }
    }

    /** Un débit/transfert exige un wallet ACTIF et un solde suffisant (aucun découvert). */
    public static void verifierDebit(WalletStatut statut, long solde, long montant) {
        verifierMontant(montant);
        if (statut != WalletStatut.ACTIF) {
            throw new WalletGeleException(statut);
        }
        if (solde < montant) {
            throw new SoldeInsuffisantException(solde, montant);
        }
    }
}
