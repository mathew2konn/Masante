package ci.masante.payment.domain.reward;

/**
 * Règles pures du cashback (CDC_06 §6.2) — <b>frontière</b> : calcul backend seul, sans Spring ni base.
 * Monnaie en <b>entiers</b> (FCFA) ; taux en <b>points de base</b> (bps : 500 = 5 %) → aucune arithmétique
 * flottante. Arrondi <b>plancher</b> (division entière).
 */
public final class ReglesCashback {

    private ReglesCashback() {
    }

    /** Cashback = base × bps / 10000, plafonné. Rejette une base négative (#7). */
    public static long calculer(long base, int tauxBps, long plafondOperation) {
        if (base < 0) {
            throw new CashbackInvalideException("La base du cashback ne peut pas être négative.");
        }
        if (tauxBps <= 0) {
            return 0;
        }
        long montant = base * tauxBps / 10000; // plancher
        if (plafondOperation > 0 && montant > plafondOperation) {
            montant = plafondOperation;
        }
        return montant;
    }

    /**
     * Clawback (reprise) proportionnel au remboursement de l'op source, avec deux garanties :
     * la somme des clawbacks ne dépasse jamais le cashback d'origine, et le remboursement qui
     * <b>solde</b> l'op source reprend le <b>reliquat exact</b> (aucun reliquat offert par arrondi).
     *
     * @param cashbackOrigine cashback initialement accordé sur l'op source
     * @param dejaClawe       cumul déjà repris (clawbacks antérieurs sur cette op source)
     * @param montantRembourse montant de CE remboursement
     * @param montantSource   montant de l'op source
     * @param soldeLOpSource  true si ce remboursement solde entièrement l'op source
     */
    public static long calculerClawback(long cashbackOrigine, long dejaClawe, long montantRembourse,
                                        long montantSource, boolean soldeLOpSource) {
        if (cashbackOrigine <= 0 || montantSource <= 0 || montantRembourse <= 0) {
            return 0;
        }
        long reliquat = cashbackOrigine - dejaClawe;
        if (reliquat <= 0) {
            return 0;
        }
        if (soldeLOpSource) {
            return reliquat; // le remboursement soldant reprend le reliquat exact
        }
        long montant = cashbackOrigine * montantRembourse / montantSource; // plancher
        return Math.min(montant, reliquat);
    }
}
