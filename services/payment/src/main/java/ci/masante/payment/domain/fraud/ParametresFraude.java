package ci.masante.payment.domain.fraud;

/**
 * Paramètres de la détection (CDC_06 §6.4) — <b>données</b> (config surchargeable), jamais codés.
 * Un snapshot de ce record est stocké dans chaque alerte pour rejouer le score a posteriori.
 */
public record ParametresFraude(
        int velociteMax,        // au-delà (>=) → motif vélocité
        long cumuleMax,         // cumul + montant au-delà (>) → motif montant
        int echecsPinMax,       // au-delà (>=) → motif échecs PIN
        int poidsVelocite,
        int poidsCumul,
        int poidsPin,
        int seuilAlerte,
        int seuilChallenge,
        int seuilGel
) {
}
