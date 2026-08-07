package ci.masante.payment.domain.reversement;

import ci.masante.payment.domain.model.CompteReversement;
import ci.masante.payment.domain.model.SensEcriture;

import java.util.ArrayList;
import java.util.List;

/**
 * Règles PURES de construction des écritures du grand livre reversement (CDC_06 §11). Aucune I/O →
 * testable unitairement (G3). Frontière : tout le jugement comptable est ici.
 *
 * <h2>Constatation — modèle à CONTRIBUTIONS SIGNÉES (aucun {@code abs()} sur une jambe fixe)</h2>
 * Axe unique : {@code +} = débit, {@code −} = crédit. Contributions déduites de l'équation du relevé :
 * <pre>
 *   TRESORERIE            : +brut
 *   COMMISSION            : −commission
 *   REMBOURSEMENT         : −remboursé
 *   A_REVERSER            : −net
 *   CREANCE_ETABLISSEMENT : report_anterieur − solde_reporte
 * </pre>
 * Σ signée = {@code brut − com − remb − net + (report − solde)} = 0 <b>par l'équation</b>
 * {@code net = brut − com − remb + report − solde} — vrai pour TOUT signe de report/solde (pas de
 * convention à supposer). Chaque contribution non nulle devient une jambe : {@code >0}→DÉBIT,
 * {@code <0}→CRÉDIT ; les contributions nulles sont omises. Une écriture a donc 0 (relevé vide) ou ≥2
 * jambes, jamais 1.
 */
public final class ReglesEcritureReversement {

    private ReglesEcritureReversement() {
    }

    public static List<JambeCalculee> constatation(long brut, long commission, long rembourse,
                                                   long net, long reportAnterieur, long soldeReporte) {
        if (brut < 0 || commission < 0 || rembourse < 0 || net < 0) {
            throw new ReversementInvalideException("Montants de base négatifs interdits.");
        }
        if (commission > brut) {
            throw new ReversementInvalideException("Commission supérieure au brut : incohérent.");
        }

        // Contributions signées (+ = débit, − = crédit).
        record Contribution(CompteReversement compte, long signe) {
        }
        List<Contribution> contributions = List.of(
                new Contribution(CompteReversement.TRESORERIE, brut),
                new Contribution(CompteReversement.COMMISSION, -commission),
                new Contribution(CompteReversement.REMBOURSEMENT, -rembourse),
                new Contribution(CompteReversement.A_REVERSER, -net),
                new Contribution(CompteReversement.CREANCE_ETABLISSEMENT, reportAnterieur - soldeReporte));

        long somme = contributions.stream().mapToLong(Contribution::signe).sum();
        if (somme != 0) {
            throw new ReversementInvalideException(
                    "Écriture de constatation déséquilibrée (Σ=" + somme + ") : équation du relevé violée.");
        }

        List<JambeCalculee> jambes = new ArrayList<>();
        int seq = 1;
        for (Contribution c : contributions) {
            if (c.signe() == 0) {
                continue;
            }
            SensEcriture sens = c.signe() > 0 ? SensEcriture.DEBIT : SensEcriture.CREDIT;
            long montant = Math.abs(c.signe());
            jambes.add(new JambeCalculee(seq++, c.compte(), sens, montant, "Constatation " + c.compte()));
        }
        if (jambes.size() == 1) {
            throw new ReversementInvalideException("Écriture à une seule jambe : impossible (déséquilibre).");
        }
        return jambes;
    }

    /** Contre-passation : mêmes comptes/montants, sens inversé, re-séquencé. */
    public static List<JambeCalculee> extourne(List<JambeCalculee> origine) {
        List<JambeCalculee> jambes = new ArrayList<>();
        int seq = 1;
        for (JambeCalculee j : origine) {
            SensEcriture inverse = j.sens() == SensEcriture.DEBIT ? SensEcriture.CREDIT : SensEcriture.DEBIT;
            jambes.add(new JambeCalculee(seq++, j.compte(), inverse, j.montant(), "Extourne " + j.compte()));
        }
        return jambes;
    }
}
