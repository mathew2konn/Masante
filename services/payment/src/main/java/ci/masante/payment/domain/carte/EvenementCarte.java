package ci.masante.payment.domain.carte;

/**
 * Déclencheurs (événements) de la machine à états carte. La transition est pilotée par ÉVÉNEMENT
 * (CDC_06 §5.1 : {@code (état, événement) → nouvel état}), et non par état cible : c'est le résultat
 * observé auprès de la passerelle (jamais déclaré par le client) qui produit l'événement.
 */
public enum EvenementCarte {

    /** Interaction porteur requise : défi 3DS (tokenisée) ou redirection hébergée. */
    INTERACTION_CLIENT_REQUISE,
    /** Authentification réussie (frictionless direct, ou défi/redirection complété avec succès). */
    AUTHENTIFICATION_REUSSIE,
    /** Autorisation bancaire accordée. */
    AUTORISATION_OK,
    /** Fonds capturés. */
    CAPTURE_OK,
    /** Refus (authentification, autorisation ou initiation). */
    REFUS,
    /** Délai dépassé (défi ou autorisation non capturée à temps). */
    EXPIRATION,
    /** Abandon explicite par le porteur. */
    ABANDON,
    /** Remboursement partiel (le cumulé reste < capturé). */
    REMBOURSEMENT_PARTIEL,
    /** Remboursement soldant (le cumulé atteint le capturé). */
    REMBOURSEMENT_TOTAL
}
