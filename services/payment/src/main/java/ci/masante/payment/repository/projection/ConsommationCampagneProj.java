package ci.masante.payment.repository.projection;

/** Cashback net consommé par une campagne (crédits − clawbacks) et son budget. */
public interface ConsommationCampagneProj {
    String getCode();

    Long getBudget();

    long getConsomme();
}
