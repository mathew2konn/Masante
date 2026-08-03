package ci.masante.payment.domain.statemachine;

import ci.masante.payment.domain.model.PaiementStatut;

import java.util.EnumMap;
import java.util.EnumSet;
import java.util.Map;
import java.util.Set;

import static ci.masante.payment.domain.model.PaiementStatut.CANCELLED;
import static ci.masante.payment.domain.model.PaiementStatut.FAILED;
import static ci.masante.payment.domain.model.PaiementStatut.INITIATED;
import static ci.masante.payment.domain.model.PaiementStatut.PENDING;
import static ci.masante.payment.domain.model.PaiementStatut.PROCESSING;
import static ci.masante.payment.domain.model.PaiementStatut.REFUNDED;
import static ci.masante.payment.domain.model.PaiementStatut.SUCCESS;

/**
 * Machine à états stricte des paiements (CDC_06 §4.2).
 *
 * <p>« Aucune transition arbitraire n'est autorisée. » Toute transition passe par
 * {@link #verifier(PaiementStatut, PaiementStatut)} avant d'être persistée. Cette classe est
 * <b>pure</b> (aucune dépendance Spring) → testable en unitaire, sans DB.</p>
 *
 * <pre>
 * INITIATED  → PENDING, PROCESSING, FAILED, CANCELLED
 * PENDING    → PROCESSING, SUCCESS, FAILED, CANCELLED
 * PROCESSING → SUCCESS, FAILED, CANCELLED
 * SUCCESS    → REFUNDED
 * FAILED / CANCELLED / REFUNDED : terminaux
 * </pre>
 */
public final class MachineEtatsPaiement {

    private static final Map<PaiementStatut, Set<PaiementStatut>> AUTORISEES =
            new EnumMap<>(PaiementStatut.class);

    static {
        AUTORISEES.put(INITIATED, EnumSet.of(PENDING, PROCESSING, FAILED, CANCELLED));
        AUTORISEES.put(PENDING, EnumSet.of(PROCESSING, SUCCESS, FAILED, CANCELLED));
        AUTORISEES.put(PROCESSING, EnumSet.of(SUCCESS, FAILED, CANCELLED));
        AUTORISEES.put(SUCCESS, EnumSet.of(REFUNDED));
        AUTORISEES.put(FAILED, EnumSet.noneOf(PaiementStatut.class));
        AUTORISEES.put(CANCELLED, EnumSet.noneOf(PaiementStatut.class));
        AUTORISEES.put(REFUNDED, EnumSet.noneOf(PaiementStatut.class));
    }

    private MachineEtatsPaiement() {
    }

    /** @return true si la transition {@code de → vers} est autorisée. */
    public static boolean estAutorisee(PaiementStatut de, PaiementStatut vers) {
        return AUTORISEES.getOrDefault(de, EnumSet.noneOf(PaiementStatut.class)).contains(vers);
    }

    /** @throws TransitionInvalideException si la transition n'est pas autorisée. */
    public static void verifier(PaiementStatut de, PaiementStatut vers) {
        if (!estAutorisee(de, vers)) {
            throw new TransitionInvalideException(de, vers);
        }
    }

    /** @return true si l'état n'admet aucune transition sortante. */
    public static boolean estTerminal(PaiementStatut statut) {
        return AUTORISEES.getOrDefault(statut, EnumSet.noneOf(PaiementStatut.class)).isEmpty();
    }
}
