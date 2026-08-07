package ci.masante.payment.service;

/**
 * Principal signé invalide (signature, fraîcheur, méthode/chemin, rejeu) → 401 GÉNÉRIQUE (anti-fuite).
 * Ne jamais préciser la cause exacte au client.
 */
public class PrincipalInvalideException extends RuntimeException {
    public PrincipalInvalideException() {
        super("Authentification invalide.");
    }
}
