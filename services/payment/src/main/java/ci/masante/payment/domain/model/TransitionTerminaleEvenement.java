package ci.masante.payment.domain.model;

import java.time.Instant;
import java.util.UUID;

/**
 * Un paiement vient d'atteindre un état terminal (lot 6, canal interne).
 *
 * <p>Publié par l'agrégat {@link Paiement} lui-même, donc par TOUT chemin qui fait passer un paiement
 * à son issue — celui du mobile money comme celui de la carte, et tout chemin à venir. C'est ce qui
 * fait la différence entre « les deux chemins connus notifient » et « aucun chemin ne peut oublier ».</p>
 *
 * <p>Porte les seules données que le service possède réellement au moment de la transition. Rien de
 * plus : ni identifiant d'établissement (le domaine n'en a pas), ni identifiant de facture patient
 * (idem) — les deviner produirait une notification confiante et fausse.</p>
 */
public record TransitionTerminaleEvenement(
        UUID paiementId,
        String correlationId,
        long montant,
        String devise,
        PaiementStatut statut,
        Instant survenuLe
) {
}
