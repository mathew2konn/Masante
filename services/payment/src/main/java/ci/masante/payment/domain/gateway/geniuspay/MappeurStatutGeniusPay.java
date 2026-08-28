package ci.masante.payment.domain.gateway.geniuspay;

import ci.masante.payment.domain.model.StatutGeniusPay;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.Locale;
import java.util.Optional;

/**
 * <b>Seul</b> endroit du service qui connaît les chaînes de statut de GeniusPay (§7.3). Partout
 * ailleurs on manipule {@link StatutGeniusPay} — un {@code equals("completed")} égaré dans un service
 * métier serait un défaut, pas un raccourci.
 *
 * <p><b>Un statut inconnu ne vaut JAMAIS succès.</b> Il n'est pas non plus traité comme un échec :
 * les deux seraient une décision inventée sur une information qu'on n'a pas. Il est journalisé et
 * rendu vide, ce qui laisse la transaction dans l'état où elle était — la réconciliation repassera.</p>
 */
public final class MappeurStatutGeniusPay {

    private static final Logger log = LoggerFactory.getLogger(MappeurStatutGeniusPay.class);

    private MappeurStatutGeniusPay() {
    }

    /** Statut de transaction (champ {@code data.status}). */
    public static Optional<StatutGeniusPay> depuisStatutApi(String statut) {
        if (statut == null || statut.isBlank()) {
            return Optional.empty();
        }
        return switch (statut.toLowerCase(Locale.ROOT)) {
            case "pending" -> Optional.of(StatutGeniusPay.EN_ATTENTE);
            case "processing" -> Optional.of(StatutGeniusPay.EN_COURS);
            case "completed", "success" -> Optional.of(StatutGeniusPay.REUSSIE);
            case "failed" -> Optional.of(StatutGeniusPay.ECHOUEE);
            case "cancelled", "canceled" -> Optional.of(StatutGeniusPay.ANNULEE);
            case "expired" -> Optional.of(StatutGeniusPay.EXPIREE);
            case "refunded" -> Optional.of(StatutGeniusPay.REMBOURSEE);
            default -> {
                log.warn("Statut GeniusPay inconnu : '{}' — aucune transition déduite (jamais un succès).", statut);
                yield Optional.empty();
            }
        };
    }

    /**
     * Type d'événement webhook. La liste est <b>fermée</b> : tout ce qui n'y figure pas est
     * explicitement non géré, jamais rangé dans un {@code default} anonyme (amendement §4.3.2).
     *
     * <p>{@code payment.initiated} y figure nommément : il est réellement émis par GeniusPay et
     * absent de la documentation. Il ne déclenche rien — nous savons déjà qu'une transaction a été
     * initiée, puisque c'est nous qui l'avons demandée.</p>
     */
    public static Optional<StatutGeniusPay> depuisEvenement(String typeEvenement) {
        if (typeEvenement == null) {
            return Optional.empty();
        }
        return switch (typeEvenement.toLowerCase(Locale.ROOT)) {
            case "payment.success", "payment.completed" -> Optional.of(StatutGeniusPay.REUSSIE);
            case "payment.failed" -> Optional.of(StatutGeniusPay.ECHOUEE);
            case "payment.cancelled", "payment.canceled" -> Optional.of(StatutGeniusPay.ANNULEE);
            case "payment.expired" -> Optional.of(StatutGeniusPay.EXPIREE);
            case "payment.refunded" -> Optional.of(StatutGeniusPay.REMBOURSEE);
            default -> Optional.empty();
        };
    }

    /**
     * Événements reçus et volontairement sans effet. Les nommer un par un est la différence entre
     * « nous avons décidé de ne rien faire » et « nous ne savions pas quoi en faire ».
     */
    public static boolean estConnuSansEffet(String typeEvenement) {
        if (typeEvenement == null) {
            return false;
        }
        String t = typeEvenement.toLowerCase(Locale.ROOT);
        return t.equals("payment.initiated")     // émis par GeniusPay, absent de la documentation
                || t.equals("payment.pending")
                || t.equals("payment.processing")
                || t.equals("webhook.test")      // déclenché depuis le tableau de bord
                || t.startsWith("cashout.");     // hors périmètre : MaSanté n'est jamais dépositaire (D8)
    }
}
