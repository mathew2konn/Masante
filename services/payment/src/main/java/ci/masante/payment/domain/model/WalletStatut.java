package ci.masante.payment.domain.model;

/**
 * États d'un portefeuille (CDC_06 §6). <b>SOURCE UNIQUE</b> : réplique {@code WalletStatut} de
 * {@code @masante/shared}. Un wallet {@code GELE} refuse tout débit/transfert (§6.4).
 */
public enum WalletStatut {
    ACTIF,
    GELE,
    CLOTURE
}
