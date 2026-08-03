package ci.masante.payment.domain.model;

/** Nature du titulaire d'un wallet (CDC_06 §6). SYSTEME = comptes techniques de contrepartie. */
public enum OwnerTypeWallet {
    PATIENT,
    ETABLISSEMENT,
    SYSTEME
}
