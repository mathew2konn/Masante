package ci.masante.payment.domain.model;

/**
 * Objet d'un paiement (CDC_06 §1/§4.3) : ce que le patient règle. Le service paiement ne connaît
 * pas le métier de ces objets — il ne fait que les tracer.
 */
public enum ObjetPaiement {
    RENDEZ_VOUS,
    ORDONNANCE,
    ANALYSE,
    RADIOLOGIE,
    HOSPITALISATION,
    AMBULANCE,
    ASSURANCE,
    CNAM,
    FACTURE,
    AUTRE
}
