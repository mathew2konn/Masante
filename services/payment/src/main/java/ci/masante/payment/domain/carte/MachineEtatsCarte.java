package ci.masante.payment.domain.carte;

import java.util.EnumMap;
import java.util.Map;

import static ci.masante.payment.domain.carte.EvenementCarte.ABANDON;
import static ci.masante.payment.domain.carte.EvenementCarte.AUTHENTIFICATION_REUSSIE;
import static ci.masante.payment.domain.carte.EvenementCarte.AUTORISATION_OK;
import static ci.masante.payment.domain.carte.EvenementCarte.CAPTURE_OK;
import static ci.masante.payment.domain.carte.EvenementCarte.EXPIRATION;
import static ci.masante.payment.domain.carte.EvenementCarte.INTERACTION_CLIENT_REQUISE;
import static ci.masante.payment.domain.carte.EvenementCarte.REFUS;
import static ci.masante.payment.domain.carte.EvenementCarte.REMBOURSEMENT_PARTIEL;
import static ci.masante.payment.domain.carte.EvenementCarte.REMBOURSEMENT_TOTAL;
import static ci.masante.payment.domain.carte.StatutCarte.ABANDONNEE;
import static ci.masante.payment.domain.carte.StatutCarte.AUTHENTIFIEE;
import static ci.masante.payment.domain.carte.StatutCarte.AUTORISEE;
import static ci.masante.payment.domain.carte.StatutCarte.CAPTUREE;
import static ci.masante.payment.domain.carte.StatutCarte.CREEE;
import static ci.masante.payment.domain.carte.StatutCarte.EN_ATTENTE_CLIENT;
import static ci.masante.payment.domain.carte.StatutCarte.EXPIREE;
import static ci.masante.payment.domain.carte.StatutCarte.REFUSEE;
import static ci.masante.payment.domain.carte.StatutCarte.REMBOURSEE;
import static ci.masante.payment.domain.carte.StatutCarte.REMBOURSEE_PARTIELLE;

/**
 * Machine à états STRICTE de la transaction carte (CDC_06 §5.1). Classe <b>pure</b> : aucune injection,
 * aucun I/O, aucun accès base → testable en unitaire. Pilotée par événement : {@code (état, événement)}
 * donne exactement un état suivant, ou lève {@link TransitionCarteIllegale}. Ne remplace PAS la machine
 * générique {@link ci.masante.payment.domain.statemachine.MachineEtatsPaiement} (interdit #1) — elle s'y
 * projette via {@link MappingStatutCarte}.
 *
 * <pre>
 * CREEE               → EN_ATTENTE_CLIENT | AUTHENTIFIEE | REFUSEE
 * EN_ATTENTE_CLIENT   → AUTHENTIFIEE | REFUSEE | EXPIREE | ABANDONNEE
 * AUTHENTIFIEE        → AUTORISEE | REFUSEE
 * AUTORISEE           → CAPTUREE | EXPIREE | ABANDONNEE
 * CAPTUREE            → REMBOURSEE_PARTIELLE | REMBOURSEE
 * REMBOURSEE_PARTIELLE→ REMBOURSEE_PARTIELLE | REMBOURSEE
 * REFUSEE / EXPIREE / ABANDONNEE / REMBOURSEE : terminaux
 * </pre>
 */
public final class MachineEtatsCarte {

    private static final Map<StatutCarte, Map<EvenementCarte, StatutCarte>> TRANSITIONS =
            new EnumMap<>(StatutCarte.class);

    static {
        TRANSITIONS.put(CREEE, Map.of(
                INTERACTION_CLIENT_REQUISE, EN_ATTENTE_CLIENT,
                AUTHENTIFICATION_REUSSIE, AUTHENTIFIEE,
                REFUS, REFUSEE));
        TRANSITIONS.put(EN_ATTENTE_CLIENT, Map.of(
                AUTHENTIFICATION_REUSSIE, AUTHENTIFIEE,
                REFUS, REFUSEE,
                EXPIRATION, EXPIREE,
                ABANDON, ABANDONNEE));
        TRANSITIONS.put(AUTHENTIFIEE, Map.of(
                AUTORISATION_OK, AUTORISEE,
                REFUS, REFUSEE));
        TRANSITIONS.put(AUTORISEE, Map.of(
                CAPTURE_OK, CAPTUREE,
                EXPIRATION, EXPIREE,
                ABANDON, ABANDONNEE));
        TRANSITIONS.put(CAPTUREE, Map.of(
                REMBOURSEMENT_PARTIEL, REMBOURSEE_PARTIELLE,
                REMBOURSEMENT_TOTAL, REMBOURSEE));
        TRANSITIONS.put(REMBOURSEE_PARTIELLE, Map.of(
                REMBOURSEMENT_PARTIEL, REMBOURSEE_PARTIELLE,
                REMBOURSEMENT_TOTAL, REMBOURSEE));
        // REFUSEE, EXPIREE, ABANDONNEE, REMBOURSEE : terminaux (aucune entrée = aucune sortie).
    }

    private MachineEtatsCarte() {
    }

    /** @return l'état résultant. @throws TransitionCarteIllegale si {@code (actuel, evenement)} n'est pas défini. */
    public static StatutCarte transition(StatutCarte actuel, EvenementCarte evenement) {
        StatutCarte suivant = TRANSITIONS.getOrDefault(actuel, Map.of()).get(evenement);
        if (suivant == null) {
            throw new TransitionCarteIllegale(actuel, evenement);
        }
        return suivant;
    }

    /** @return true si l'événement est applicable à l'état (sans lever). */
    public static boolean estAutorisee(StatutCarte actuel, EvenementCarte evenement) {
        return TRANSITIONS.getOrDefault(actuel, Map.of()).containsKey(evenement);
    }

    /** @return true si l'état n'admet aucune transition sortante. */
    public static boolean estTerminal(StatutCarte statut) {
        return TRANSITIONS.getOrDefault(statut, Map.of()).isEmpty();
    }

    /** Vue immuable des transitions sortantes d'un état (pour l'introspection et les tests). */
    public static Map<EvenementCarte, StatutCarte> transitionsDepuis(StatutCarte statut) {
        return TRANSITIONS.getOrDefault(statut, Map.of());
    }
}
