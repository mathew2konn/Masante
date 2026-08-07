package ci.masante.payment.domain.carte;

/**
 * Modalité d'interaction avec le porteur, propriété de la PASSERELLE (CDC_06 §5, ADR-015).
 * L'abstraction correcte n'est pas « nous pilotons 3DS » mais « la passerelle possède l'interaction ».
 */
public enum ModaliteCarte {
    /** La passerelle fournit un token client ; le défi 3DS renvoie un challengeRef (modèle Stripe/Adyen). */
    TOKENISEE,
    /** La passerelle fournit une URL hébergée ; retour via return_url + webhook (modèle CinetPay/PayDunya…). */
    REDIRIGEE
}
