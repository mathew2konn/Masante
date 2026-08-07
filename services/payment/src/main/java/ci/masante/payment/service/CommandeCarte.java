package ci.masante.payment.service;

import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.model.ObjetPaiement;

import java.util.UUID;

/**
 * Commande applicative d'un paiement carte (issue de la requête HTTP validée, §7.1). FRONTIÈRE PCI :
 * {@code referenceClient} est un TOKEN / une référence de session fournie par la passerelle — JAMAIS un
 * PAN ni un CVV (le filtre du contrôleur rejette tout ce qui ressemble à un numéro de carte, §9).
 *
 * @param psp              PSP visé (clé de dispatch — jamais de {@code if psp == …}).
 * @param referenceClient  token (tokenisée) ou déclencheur de session hébergée (redirigée).
 * @param montant          montant à autoriser ({@link Montant} : valeur entière + devise).
 * @param utilisateurRef   référence de l'utilisateur (posée par la passerelle, en-tête) — vault + propriété.
 * @param objet            objet réglé (tracé, jamais interprété métier).
 * @param returnUrl        URL de retour (modalité redirigée) ; null sinon.
 * @param enregistrerCarte true pour enrôler la carte au vault après autorisation (chemin frictionless).
 * @param factureId        facture à solder à la capture (null si paiement hors facture).
 */
public record CommandeCarte(
        String psp,
        String referenceClient,
        Montant montant,
        String utilisateurRef,
        ObjetPaiement objet,
        String returnUrl,
        boolean enregistrerCarte,
        String correlationId,
        String etablissementRef,
        String patientRef,
        UUID factureId
) {
}
