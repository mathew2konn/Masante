package ci.masante.payment.domain.carte;

import ci.masante.payment.domain.model.PaiementStatut;

/**
 * Projection TOTALE du sous-état carte vers le {@link PaiementStatut} générique (CDC_06 §5.2).
 *
 * <p>Le {@code switch} est <b>exhaustif par construction</b> (aucun {@code default}) : ajouter une valeur
 * à {@link StatutCarte} sans l'y mapper casse la compilation — la totalité est garantie par le compilateur.
 * Point clé : {@code REMBOURSEE_PARTIELLE → SUCCESS} (le remboursement partiel ne change PAS le statut
 * générique, seul {@code montant_rembourse} bouge) → c'est ce qui permet de ne jamais toucher à la machine
 * partagée (interdit #1).</p>
 */
public final class MappingStatutCarte {

    private MappingStatutCarte() {
    }

    public static PaiementStatut versPaiement(StatutCarte statut) {
        return switch (statut) {
            case CREEE -> PaiementStatut.INITIATED;
            case EN_ATTENTE_CLIENT -> PaiementStatut.PENDING;
            case AUTHENTIFIEE, AUTORISEE -> PaiementStatut.PROCESSING;
            case CAPTUREE, REMBOURSEE_PARTIELLE -> PaiementStatut.SUCCESS;
            case REMBOURSEE -> PaiementStatut.REFUNDED;
            case REFUSEE -> PaiementStatut.FAILED;
            case EXPIREE, ABANDONNEE -> PaiementStatut.CANCELLED;
        };
    }
}
