package ci.masante.payment.domain.reversement.versement;

import ci.masante.payment.domain.model.TypeDestination;

/**
 * Contrat d'une passerelle de décaissement (CDC_06 §11 — OCP obligatoire, miroir de
 * {@code PasserellePaiement}). <b>Interdit #5</b> : jamais de {@code if type == MOBILE_MONEY … else …}.
 * Ajouter un opérateur réel (Orange Money, virement bancaire) = <b>ajouter un bean</b> ; la sélection se
 * fait par {@link #supporte(TypeDestination)} via {@link RegistrePasserellesReversement}.
 *
 * <p><b>Principe (miroir du 3DS jamais déclaré par le client, P5.4a)</b> : le résultat du versement n'est
 * JAMAIS déclaré par l'appelant. Il est décidé par la passerelle ({@link #verser}) ou relu via
 * {@link #statut(String)} (vérité passerelle — graine du rapprochement 2 sources S11.x).</p>
 */
public interface PasserelleReversement {

    /** @return true si cette passerelle prend en charge ce type de destination (clé de dispatch). */
    boolean supporte(TypeDestination type);

    /** Verse effectivement (SIMULÉ, FT5) ; renvoie l'issue scellée (EXÉCUTÉ / ÉCHOUÉ) + réf opérateur. */
    ResultatDecaissement verser(DemandeDecaissement demande);

    /** Relit le statut AUTORITATIF auprès de la passerelle (réconciliation S11.x — jamais l'appelant). */
    ResultatDecaissement statut(String referencePasserelle);
}
