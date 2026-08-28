package ci.masante.payment.domain.gateway;

import ci.masante.payment.domain.model.PaiementStatut;

/**
 * Résultat renvoyé par une passerelle. {@code statut} est un état de la machine (§4.2) ;
 * {@code referenceOperateur} est la référence chez le prestataire (factice en simulé).
 *
 * <p>{@code checkout} est <b>facultatif</b> et vaut {@code null} pour les passerelles qui encaissent
 * sans redirection. Il a été ajouté pour GeniusPay (lot 7), dont le mode de paiement est une page de
 * checkout hébergée : sans lui, le port n'aurait pas pu porter le seul élément que le patient doit
 * réellement recevoir. L'ajout est <b>strictement additif</b> — le constructeur à trois arguments
 * existe toujours, et aucune passerelle antérieure n'a été modifiée.</p>
 */
public record ResultatPaiement(
        PaiementStatut statut,
        String referenceOperateur,
        String message,
        DetailCheckout checkout
) {

    /** Passerelle sans redirection : aucun détail de checkout. */
    public ResultatPaiement(PaiementStatut statut, String referenceOperateur, String message) {
        this(statut, referenceOperateur, message, null);
    }

    /**
     * Détail d'un encaissement par page hébergée.
     *
     * <p>{@code expireLe} est l'échéance <b>renvoyée par le prestataire</b>, jamais un
     * « maintenant + N heures » calculé chez nous : la vérification en bac à sable a montré trente
     * minutes là où la documentation en annonce vingt-quatre heures, et une échéance recopiée de la
     * documentation aurait fait tenir pour ouvert un lien déjà mort.</p>
     *
     * <p>{@code frais} et {@code net} viennent eux aussi du prestataire et ne se recalculent pas :
     * les reconstituer à partir d'un barème produirait des écarts sur le reçu remis au partenaire.</p>
     */
    public record DetailCheckout(
            String checkoutUrl,
            java.time.Instant expireLe,
            Long frais,
            Long net,
            String canalReel
    ) {
    }
}
