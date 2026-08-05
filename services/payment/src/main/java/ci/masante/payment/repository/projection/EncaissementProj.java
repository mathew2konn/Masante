package ci.masante.payment.repository.projection;

/** Encaissement passerelle (SUCCESS) cumulé d'une facture, comparé à son montant réglé. */
public interface EncaissementProj {
    String getNumero();

    long getMontantRegle();

    long getSommePasserelle();
}
