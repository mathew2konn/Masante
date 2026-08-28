package ci.masante.payment.domain.model;

/**
 * Sous-état <b>backend-only</b> d'une transaction GeniusPay (ADR-044 §B3, prompt v3 §8.3).
 *
 * <p>Il ne remplace pas {@link PaiementStatut}, il le <b>précise</b>. La machine partagée avec le
 * mobile et le web ({@code @masante/shared}) reste intacte : elle n'apprend jamais qu'un prestataire
 * distingue « expiré » de « échoué ». Le motif est celui de {@code StatutCarte} (P5.4a), repris et
 * non réinventé.</p>
 *
 * <p><b>Pourquoi ce sous-état existe.</b> Deux besoins que la machine partagée ne peut pas porter
 * sans mentir à quelqu'un : la réconciliation a besoin de savoir qu'un lien de checkout est arrivé à
 * échéance (ce n'est pas un refus), et {@link #INITIEE_INCERTAINE} désigne un état qui n'a
 * <b>aucun équivalent</b> côté patient — « nous ne savons pas si la transaction existe chez le
 * prestataire ». L'exposer tel quel affolerait ; le taire ferait perdre l'information à
 * l'exploitation.</p>
 */
public enum StatutGeniusPay {

    /** Persistée chez nous, l'appel réseau n'a pas encore eu lieu. */
    INITIEE,

    /**
     * L'appel a échoué en réseau (délai dépassé, coupure) : <b>on ne sait pas</b> si GeniusPay a créé
     * la transaction. C'est l'état qui interdit le rejeu (§7.4) — rejouer produirait potentiellement
     * deux débits sur un patient.
     */
    INITIEE_INCERTAINE,

    /** Checkout créé, lien remis au patient, rien n'est encore payé. */
    EN_ATTENTE,

    /** Le prestataire a pris la main sur l'encaissement. */
    EN_COURS,

    REUSSIE,
    ECHOUEE,
    ANNULEE,

    /**
     * Le lien de checkout a expiré sans paiement. Distinct d'un échec pour la réconciliation et le
     * back-office ; <b>le patient, lui, voit « échoué »</b> — la nuance ne lui apprendrait rien
     * d'actionnable.
     */
    EXPIREE,

    REMBOURSEE;

    /**
     * Projection sur la machine partagée. {@code switch} <b>exhaustif et sans {@code default}</b> :
     * ajouter une valeur à cette enum sans décider de sa projection devient une <b>erreur de
     * compilation</b>, jamais un état silencieusement rangé dans un fourre-tout.
     */
    public PaiementStatut versStatutPartage() {
        return switch (this) {
            // INITIEE_INCERTAINE se projette sur INITIATED et JAMAIS sur PENDING : PENDING affirmerait
            // qu'une transaction attend chez le prestataire, alors que c'est précisément ce qu'on ignore.
            case INITIEE, INITIEE_INCERTAINE -> PaiementStatut.INITIATED;
            case EN_ATTENTE -> PaiementStatut.PENDING;
            case EN_COURS -> PaiementStatut.PROCESSING;
            case REUSSIE -> PaiementStatut.SUCCESS;
            // EXPIREE se projette sur FAILED : la machine partagée n'a pas d'état « expiré », et lui en
            // ajouter un pour un détail de prestataire modifierait un contrat validé G5 côté mobile et web.
            case ECHOUEE, EXPIREE -> PaiementStatut.FAILED;
            case ANNULEE -> PaiementStatut.CANCELLED;
            case REMBOURSEE -> PaiementStatut.REFUNDED;
        };
    }

    /** Vrai si aucune évolution n'est plus attendue (hors remboursement d'une réussite). */
    public boolean estTerminal() {
        return switch (this) {
            case REUSSIE, ECHOUEE, ANNULEE, EXPIREE, REMBOURSEE -> true;
            case INITIEE, INITIEE_INCERTAINE, EN_ATTENTE, EN_COURS -> false;
        };
    }
}
