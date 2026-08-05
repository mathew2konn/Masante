package ci.masante.payment.repository.projection;

/** État d'une facture pour le contrôle de cohérence statut ↔ règlement. */
public interface FactureEtatProj {
    String getNumero();

    String getStatut();

    long getMontantRegle();

    long getResteAPayer();
}
