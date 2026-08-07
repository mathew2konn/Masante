package ci.masante.payment.domain.carte;

import java.time.Instant;

/**
 * Résultat d'une initiation carte — interface SCELLÉE : le domaine ne connaît que ces quatre issues
 * possibles, quelle que soit la passerelle (aucun {@code if psp == …}). FRONTIÈRE PCI : ne porte que des
 * métadonnées non sensibles fournies par la passerelle (marque, last4, expiration, NTID).
 *
 * <p>Homonyme volontairement distinct de {@code service.ResultatInitiation} (résultat d'initiation d'un
 * paiement générique) : paquets séparés, aucun fichier n'importe les deux.</p>
 */
public sealed interface ResultatInitiation {

    /** Succès direct sans interaction porteur (frictionless). */
    record Frictionless(String refPasserelle, String ntid, String marque, String last4,
                        int expMois, int expAnnee) implements ResultatInitiation {
    }

    /** Défi 3DS requis (modalité tokenisée) : le porteur doit authentifier via {@code challengeRef}. */
    record DefiRequis(String refPasserelle, String challengeRef, Instant expireLe)
            implements ResultatInitiation {
    }

    /** Redirection requise (modalité redirigée) : le porteur paie sur la page hébergée {@code urlPaiement}. */
    record RedirectionRequise(String refPasserelle, String urlPaiement, Instant expireLe)
            implements ResultatInitiation {
    }

    /** Refus à l'initiation (aucune transaction exploitable). {@code motifPublic} est générique (anti-fuite). */
    record Refusee(String codeRefus, String motifPublic) implements ResultatInitiation {
    }
}
