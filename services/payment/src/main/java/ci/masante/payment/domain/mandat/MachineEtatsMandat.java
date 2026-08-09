package ci.masante.payment.domain.mandat;

import java.util.EnumMap;
import java.util.Map;

import static ci.masante.payment.domain.mandat.ActionMandat.ANNULER;
import static ci.masante.payment.domain.mandat.ActionMandat.EXPIRER;
import static ci.masante.payment.domain.mandat.ActionMandat.REPRENDRE;
import static ci.masante.payment.domain.mandat.ActionMandat.SUSPENDRE;
import static ci.masante.payment.domain.mandat.MandatStatut.ACTIF;
import static ci.masante.payment.domain.mandat.MandatStatut.ANNULE;
import static ci.masante.payment.domain.mandat.MandatStatut.EXPIRE;
import static ci.masante.payment.domain.mandat.MandatStatut.SUSPENDU;

/**
 * Machine à états STRICTE d'un mandat (CDC_06 §5.4). Classe pure (aucune injection, aucun I/O) → testable
 * en unitaire. {@code (état, action) → état suivant}, ou lève {@link TransitionMandatIllegale}.
 *
 * <pre>
 * ACTIF     → SUSPENDU (SUSPENDRE) | ANNULE (ANNULER) | EXPIRE (EXPIRER)
 * SUSPENDU  → ACTIF (REPRENDRE)    | ANNULE (ANNULER) | EXPIRE (EXPIRER)
 * ANNULE / EXPIRE : terminaux
 * </pre>
 */
public final class MachineEtatsMandat {

    private static final Map<MandatStatut, Map<ActionMandat, MandatStatut>> TRANSITIONS =
            new EnumMap<>(MandatStatut.class);

    static {
        TRANSITIONS.put(ACTIF, Map.of(
                SUSPENDRE, SUSPENDU,
                ANNULER, ANNULE,
                EXPIRER, EXPIRE));
        TRANSITIONS.put(SUSPENDU, Map.of(
                REPRENDRE, ACTIF,
                ANNULER, ANNULE,
                EXPIRER, EXPIRE));
        // ANNULE, EXPIRE : terminaux.
    }

    private MachineEtatsMandat() {
    }

    public static MandatStatut transition(MandatStatut actuel, ActionMandat action) {
        MandatStatut suivant = TRANSITIONS.getOrDefault(actuel, Map.of()).get(action);
        if (suivant == null) {
            throw new TransitionMandatIllegale(actuel, action);
        }
        return suivant;
    }

    public static boolean estAutorisee(MandatStatut actuel, ActionMandat action) {
        return TRANSITIONS.getOrDefault(actuel, Map.of()).containsKey(action);
    }
}
