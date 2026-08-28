package ci.masante.payment.service;

/**
 * Aucun secret webhook n'est enregistré pour ce marchand. Le message nomme l'établissement — jamais
 * le secret, jamais le slug : un message d'erreur est ce qui finit le plus sûrement dans une trace.
 */
public class SecretMarchandAbsentException extends RuntimeException {

    public SecretMarchandAbsentException(String etablissementRef) {
        super("Aucun secret webhook enregistré pour l'établissement " + etablissementRef
              + " : le webhook doit être créé chez le prestataire, et son whsec_ n'est renvoyé qu'une fois.");
    }
}
