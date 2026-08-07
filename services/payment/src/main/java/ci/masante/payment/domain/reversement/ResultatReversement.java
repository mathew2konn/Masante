package ci.masante.payment.domain.reversement;

import java.util.List;

/**
 * Résultat PUR du calcul d'un relevé de reversement. Invariant garanti par construction :
 * {@code montantNetAReverser + soldeReporte = montantBrutDu − montantCommission − montantRembourse
 * + reportAnterieur} (= {@code ck_rev_equation} en base), avec {@code net ≥ 0}, {@code report ≤ 0},
 * {@code soldeReporte ≤ 0}, et exclusivité ({@code net = 0} OU {@code soldeReporte = 0}).
 */
public record ResultatReversement(
        long montantBrutDu,
        int tauxCommissionBps,
        long montantCommission,
        long montantRembourse,
        long reportAnterieur,
        long montantNetAReverser,
        long soldeReporte,
        List<LigneCalculeeReversement> lignes) {
}
