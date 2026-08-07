package ci.masante.payment.domain.reversement;

import java.util.ArrayList;
import java.util.List;

/**
 * Règles PURES du calcul des sommes dues à un établissement (CDC_06 §11). Aucune I/O : reçoit
 * l'assiette déjà bornée (établissement, fenêtre, cut-off, non déjà imputé) et le taux résolu, et
 * décide des montants. Frontière : tout le JUGEMENT est ici, jamais dans un contrôleur → testable
 * unitairement (G3 pur, sans base).
 *
 * <h2>Monnaie & arrondi — XOF entier, I3 exact</h2>
 * Le FCFA n'a pas de sous-unité : tous les montants sont des {@code long} de francs entiers. La
 * commission est calculée <b>par ligne</b> ({@code montantRegle × bps / 10000}, division entière =
 * arrondi PLANCHER, en faveur de l'établissement), puis les totaux d'en-tête sont la SOMME des
 * lignes — jamais un recalcul sur le brut. Ainsi {@code Σ lignes = en-tête} exactement (I3), quel que
 * soit l'arrondi (même principe que {@code ReglesCashback} / {@code ReglesControle}).
 *
 * <h2>Report chaîné (§11, ADR-016 §2)</h2>
 * {@code reportAnterieur ≤ 0} est le {@code soldeReporte} du relevé précédent (fourni par le service
 * via le chaînage {@code releve_precedent_id}, jamais par une requête de date ambiguë). Si les
 * remboursements + le report dépassent l'encaissement net, on ne décaisse RIEN : le net est 0 et la
 * dette bascule dans {@code soldeReporte} (≤ 0), reprise au relevé suivant.
 */
public final class ReglesReversement {

    private ReglesReversement() {
    }

    public static ResultatReversement calculer(List<EncaissementImputable> encaissements,
                                               List<RemboursementImputable> remboursements,
                                               int tauxCommissionBps, long reportAnterieur) {
        if (tauxCommissionBps < 0 || tauxCommissionBps > 10000) {
            throw new ReversementInvalideException("Taux de commission hors bornes : " + tauxCommissionBps);
        }
        if (reportAnterieur > 0) {
            throw new ReversementInvalideException("Le report antérieur doit être ≤ 0 : " + reportAnterieur);
        }

        List<LigneCalculeeReversement> lignes = new ArrayList<>();
        long brut = 0L;
        long commissionTotale = 0L;

        for (EncaissementImputable e : encaissements) {
            if (e.montantRegle() < 0) {
                throw new ReversementInvalideException("Montant réglé négatif : facture " + e.numero());
            }
            // Division entière = arrondi PLANCHER, pas de flottant. Montants XOF réalistes très en
            // deçà de l'overflow (montantRegle × 10000 ≪ Long.MAX).
            long commission = e.montantRegle() * (long) tauxCommissionBps / 10000L;
            brut += e.montantRegle();
            commissionTotale += commission;
            lignes.add(LigneCalculeeReversement.facture(e.factureId(), e.numero(), e.soldeeA(),
                    e.montantRegle(), commission));
        }

        long rembourseTotal = 0L;
        for (RemboursementImputable r : remboursements) {
            if (r.montant() < 0) {
                throw new ReversementInvalideException("Montant de remboursement négatif : " + r.reference());
            }
            rembourseTotal += r.montant();
            lignes.add(LigneCalculeeReversement.remboursement(r.remboursementId(), r.reference(),
                    r.creeLe(), r.montant()));
        }

        long netTheorique = brut - commissionTotale - rembourseTotal + reportAnterieur;
        long net = netTheorique >= 0 ? netTheorique : 0L;
        long soldeReporte = netTheorique >= 0 ? 0L : netTheorique;

        return new ResultatReversement(brut, tauxCommissionBps, commissionTotale, rembourseTotal,
                reportAnterieur, net, soldeReporte, lignes);
    }
}
