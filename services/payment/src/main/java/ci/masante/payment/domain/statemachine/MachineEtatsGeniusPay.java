package ci.masante.payment.domain.statemachine;

import ci.masante.payment.domain.model.StatutGeniusPay;

import java.util.EnumMap;
import java.util.EnumSet;
import java.util.Map;
import java.util.Set;

/**
 * Machine à états du sous-état GeniusPay (§8.4). Classe <b>pure</b> — aucune dépendance Spring, JPA
 * ni Jackson — donc éprouvable sans base ni contexte.
 *
 * <pre>
 * INITIEE ──────────────► EN_ATTENTE ──► EN_COURS ──► REUSSIE ──► REMBOURSEE
 *    │                        │             │
 *    └──► INITIEE_INCERTAINE ─┘             ├──► ECHOUEE   (terminal)
 *                                           ├──► ANNULEE   (terminal)
 *                                           └──► EXPIREE   (terminal)
 * </pre>
 *
 * <p><b>Un état terminal ne se remplace jamais</b>, sauf {@code REUSSIE → REMBOURSEE}. Une transition
 * interdite n'est pas une anomalie : c'est le <b>cas normal</b> d'un renvoi tardif de webhook (le
 * prestataire réessaie cinq fois). Elle est donc journalisée et l'événement classé
 * {@code IGNORE_DOUBLON}, jamais {@code ERREUR} — traiter un renvoi comme un incident remplirait la
 * file d'alertes de faits parfaitement normaux, et les vraies s'y perdraient.</p>
 *
 * <p>Cette classe est le <b>seul</b> automate du sous-état : le traitement webhook (§8.4) et la
 * réconciliation (§8.5) l'appellent tous deux. Deux implémentations du même automate divergeraient
 * un jour, et l'écart porterait sur de l'argent.</p>
 */
public final class MachineEtatsGeniusPay {

    private static final Map<StatutGeniusPay, Set<StatutGeniusPay>> AUTORISEES =
            new EnumMap<>(StatutGeniusPay.class);

    static {
        AUTORISEES.put(StatutGeniusPay.INITIEE, EnumSet.of(
                StatutGeniusPay.INITIEE_INCERTAINE, StatutGeniusPay.EN_ATTENTE,
                StatutGeniusPay.EN_COURS, StatutGeniusPay.REUSSIE, StatutGeniusPay.ECHOUEE,
                StatutGeniusPay.ANNULEE, StatutGeniusPay.EXPIREE));
        // L'incertitude se lève dans les deux sens : le webhook peut annoncer n'importe quelle issue,
        // y compris un succès direct, sans passer par EN_ATTENTE que nous n'avons jamais observé.
        AUTORISEES.put(StatutGeniusPay.INITIEE_INCERTAINE, EnumSet.of(
                StatutGeniusPay.EN_ATTENTE, StatutGeniusPay.EN_COURS, StatutGeniusPay.REUSSIE,
                StatutGeniusPay.ECHOUEE, StatutGeniusPay.ANNULEE, StatutGeniusPay.EXPIREE));
        AUTORISEES.put(StatutGeniusPay.EN_ATTENTE, EnumSet.of(
                StatutGeniusPay.EN_COURS, StatutGeniusPay.REUSSIE, StatutGeniusPay.ECHOUEE,
                StatutGeniusPay.ANNULEE, StatutGeniusPay.EXPIREE));
        AUTORISEES.put(StatutGeniusPay.EN_COURS, EnumSet.of(
                StatutGeniusPay.REUSSIE, StatutGeniusPay.ECHOUEE, StatutGeniusPay.ANNULEE,
                StatutGeniusPay.EXPIREE));
        AUTORISEES.put(StatutGeniusPay.REUSSIE, EnumSet.of(StatutGeniusPay.REMBOURSEE));
        AUTORISEES.put(StatutGeniusPay.ECHOUEE, EnumSet.noneOf(StatutGeniusPay.class));
        AUTORISEES.put(StatutGeniusPay.ANNULEE, EnumSet.noneOf(StatutGeniusPay.class));
        AUTORISEES.put(StatutGeniusPay.EXPIREE, EnumSet.noneOf(StatutGeniusPay.class));
        AUTORISEES.put(StatutGeniusPay.REMBOURSEE, EnumSet.noneOf(StatutGeniusPay.class));
    }

    private MachineEtatsGeniusPay() {
    }

    public static boolean estAutorisee(StatutGeniusPay de, StatutGeniusPay vers) {
        if (de == vers) {
            // Un renvoi à l'identique n'est pas une transition : il ne fait rien avancer et ne doit
            // pas non plus lever. Il est refusé ici, et traité en amont comme un doublon.
            return false;
        }
        return AUTORISEES.getOrDefault(de, EnumSet.noneOf(StatutGeniusPay.class)).contains(vers);
    }
}
