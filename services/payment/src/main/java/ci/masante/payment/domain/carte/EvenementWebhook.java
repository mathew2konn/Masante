package ci.masante.payment.domain.carte;

import java.time.Instant;

/**
 * Événement de webhook APRÈS parsing/normalisation par la passerelle (CDC_06 §5, §6). Distinct de
 * {@link EvenementCarte} (le déclencheur de la machine à états) : celui-ci porte l'{@code evenementId}
 * (déduplication base), la {@code refPasserelle} (corrélation) et l'{@link IssuePasserelle} autoritative,
 * plus les métadonnées non sensibles. Le service en dérive l'événement de transition.
 *
 * @param evenementId identifiant unique de l'événement chez la passerelle (idempotence webhook).
 * @param horodatage  instant d'émission déclaré par le PSP, extrait du CORPS SIGNÉ (donc lié par le HMAC) →
 *                    sert le contrôle de fraîcheur anti-rejeu (§7.3). {@code null} si absent → rejeté.
 */
public record EvenementWebhook(String evenementId, String type, String refPasserelle, IssuePasserelle issue,
                               Instant horodatage, String ntid, String marque, String last4, Integer expMois,
                               Integer expAnnee, String codeRefus) {
}
