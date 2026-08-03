package ci.masante.payment.domain.coverage;

/** Nature du tiers payant (CDC_06 §8). CNAM et assurances privées pour P5.1. */
public enum TypePriseEnCharge {
    CNAM,
    ASSURANCE,
    MUTUELLE,
    ENTREPRISE,
    ONG,
    PROGRAMME_GOUVERNEMENTAL
}
