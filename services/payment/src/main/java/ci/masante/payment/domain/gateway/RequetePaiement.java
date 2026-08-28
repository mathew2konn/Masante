package ci.masante.payment.domain.gateway;

import ci.masante.payment.domain.model.ObjetPaiement;

/**
 * Requête transmise à une passerelle (CDC_06 §3.3). Immuable. Le montant est en FCFA (entier).
 *
 * <p>{@code etablissementRef} a été ajouté pour le montage A du lot 7 : un compte marchand par
 * établissement signifie qu'une passerelle doit savoir <b>pour le compte de qui</b> elle encaisse
 * avant de choisir la clé d'API. L'ajout est <b>strictement additif</b> — le constructeur d'origine
 * subsiste et vaut {@code null}, ce qui convient aux passerelles à compte unique.</p>
 *
 * <p>{@code factureId} suit la même logique : c'est la facture visée à l'initiation, celle que le
 * checkout doit solder en entier (D6). Elle n'est pas déduite d'un état ultérieur.</p>
 */
public record RequetePaiement(
        String referenceInterne,
        long montant,
        String devise,
        String canal,
        ObjetPaiement objet,
        String telephone,
        String correlationId,
        String etablissementRef,
        java.util.UUID factureId
) {

    /** Passerelle à compte unique, sans facture visée : forme d'origine, conservée intacte. */
    public RequetePaiement(String referenceInterne, long montant, String devise, String canal,
                           ObjetPaiement objet, String telephone, String correlationId) {
        this(referenceInterne, montant, devise, canal, objet, telephone, correlationId, null, null);
    }
}
