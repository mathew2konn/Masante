package ci.masante.payment.domain.carte;

/**
 * Sous-état d'une transaction carte (CDC_06 §5) — <b>backend-only</b>. N'est JAMAIS exposé au front
 * (interdit #8) : le front ne voit que le {@link ci.masante.payment.domain.model.PaiementStatut} générique
 * obtenu via {@link MappingStatutCarte}. Un seul état {@link #EN_ATTENTE_CLIENT} couvre les deux modalités
 * (défi 3DS tokenisé <i>ou</i> redirection hébergée) : le domaine ne connaît pas la marque du PSP.
 */
public enum StatutCarte {

    CREEE,
    /** Le porteur doit interagir : défi 3DS2 (tokenisée) ou page hébergée (redirigée). */
    EN_ATTENTE_CLIENT,
    AUTHENTIFIEE,
    AUTORISEE,
    CAPTUREE,
    REMBOURSEE_PARTIELLE,
    REMBOURSEE,

    // Terminaux d'échec.
    REFUSEE,
    EXPIREE,
    ABANDONNEE
}
