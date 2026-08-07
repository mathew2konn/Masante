package ci.masante.payment.domain.model;

/**
 * Plan de comptes du grand livre reversement (CDC_06 §11). Enum backend-only (promu {@code @masante/shared}
 * quand un écran l'exploitera).
 */
public enum CompteReversement {
    /** Contrepartie de caisse/banque : encaissements reconnus, versements sortants. */
    TRESORERIE,
    /** Produit de la plateforme (commission retenue). */
    COMMISSION,
    /** Dette envers l'établissement (à reverser). */
    A_REVERSER,
    /** Remboursements imputés (décote de l'assiette). */
    REMBOURSEMENT,
    /** Créance/dette de report entre périodes (solde reporté chaîné). */
    CREANCE_ETABLISSEMENT,
    /** Frais de passerelle de décaissement — RÉSERVÉ P5.5b-2 (non utilisé en b-1). */
    FRAIS_PASSERELLE
}
