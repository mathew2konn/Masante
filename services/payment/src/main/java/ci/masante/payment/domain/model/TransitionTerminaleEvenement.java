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
 * <p>Porte les seules données que le service possède réellement au moment de la transition — rien de
 * plus, jamais deviné.</p>
 *
 * <p><b>Correction du 2026-09-04 (B4, ADR-056)</b> : ce Javadoc affirmait jusqu'ici « ni identifiant
 * d'établissement (le domaine n'en a pas), ni identifiant de facture patient (idem) ». C'était
 * inexact — {@link Paiement} porte {@code etablissementRef} et {@code factureId} depuis le lot 6 ;
 * ce qui était vrai, c'est qu'ils pouvaient être NULS faute d'émetteur, Laravel n'initiant alors
 * aucun paiement. Le champ existait ; B4 en devient l'émetteur, et les recopie ici — jamais devinés,
 * toujours ceux de l'agrégat. {@code fraisPasserelle} est neuf (S3) : {@code null} quand le canal ne
 * les connaît pas au moment de la transition (le webhook GeniusPay ne les porte pas toujours), et
 * c'est au consommateur de le dire plutôt que de laisser croire à un frais nul.</p>
 *
 * <p>{@code canal} — AJOUTÉ EN COURS D'EXÉCUTION DE B4, absent du plan initial. {@code carte} et le
 * mobile money portent EUX AUSSI un {@code etablissementRef} (constaté en relisant
 * {@code ServiceCarte}/{@code ServicePaiement}) : sans le canal, Laravel n'aurait eu aucun moyen de
 * distinguer un paiement GeniusPay (montage A, commission MaSanté) d'un paiement carte ou mobile
 * money réglé chez le même établissement — et aurait dû soit calculer une commission sur TOUS les
 * canaux (décision de politique commerciale jamais prise), soit deviner. {@code canal} vient de
 * {@link Paiement#getCanal()}, jamais recalculé.</p>
 */
public record TransitionTerminaleEvenement(
        UUID paiementId,
        String correlationId,
        long montant,
        String devise,
        PaiementStatut statut,
        Instant survenuLe,
        String etablissementRef,
        UUID factureId,
        Long fraisPasserelle,
        String canal
) {
}
