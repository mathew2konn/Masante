package ci.masante.payment.service;

/**
 * Résultat de vérification (§7.4) : {@code integre} = le hash recalculé correspond ; {@code signee}
 * = un sceau est présent ; {@code signatureValide} = la signature vérifie avec la clé publique stockée.
 */
public record VerificationSignature(boolean integre, boolean signee, boolean signatureValide, String algorithme) {
}
