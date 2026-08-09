package ci.masante.payment.domain.carte;

import ci.masante.payment.domain.gateway.ResultatRemboursement;

import java.util.Map;

/**
 * Contrat d'une passerelle carte (CDC_06 §5, ADR-015) — OCP : ajouter un PSP = ajouter une implémentation,
 * jamais un {@code if psp == …} (interdit #5). La sélection se fait par {@link RegistrePasserellesCarte}
 * sur {@link #psp()}.
 *
 * <p><b>Principe §1.2</b> : le résultat 3DS n'est JAMAIS déclaré par le client. Le backend le lit via
 * {@link #recupererStatut(String)} ou via le webhook signé. Réutilise {@link ResultatRemboursement} du
 * domaine passerelle générique (pas de duplication).</p>
 */
public interface PasserelleCarte {

    /** Identifiant du PSP (clé de dispatch). */
    String psp();

    /** Modalité d'interaction porteur possédée par ce PSP. */
    ModaliteCarte modalite();

    /** Initie le paiement ; renvoie l'issue scellée (frictionless / défi / redirection / refus). */
    ResultatInitiation initier(DemandeCarte demande);

    /** Lit le statut AUTORITATIF auprès du PSP (la vérité — jamais le client, §1.2). */
    StatutPasserelle recupererStatut(String refPasserelle);

    /** Capture les fonds d'une autorisation valide. */
    ResultatCapture capturer(String refPasserelle, Montant montant);

    /**
     * Débit récurrent MIT (Merchant-Initiated Transaction, CDC_06 §5.4) : initié marchand, porteur absent,
     * SANS défi 3DS — s'appuie sur le token du vault + le {@code networkTransactionId} de la 1re transaction.
     * Le RÉSULTAT est décidé par la passerelle, jamais par l'appelant (§1.2). OCP : dispatch par {@link #psp()}.
     */
    ResultatDebitRecurrent debiterRecurrent(String token, String networkTransactionId, Montant montant,
                                            String referenceMandat);

    /** Rembourse (total ou partiel) vers la carte d'origine (interdit #10 : jamais un autre instrument). */
    ResultatRemboursement rembourser(String refPasserelle, Montant montant, String motif);

    /** Vérifie la signature (HMAC) sur le CORPS BRUT, avant toute désérialisation (§7.3). */
    boolean verifierSignature(byte[] corpsBrut, Map<String, String> entetes);

    /** Parse + normalise un événement de webhook (déjà vérifié) en {@link EvenementWebhook}. */
    EvenementWebhook parserEvenement(byte[] corpsBrut);
}
