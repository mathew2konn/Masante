package ci.masante.payment.domain.reversement;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Conservation du REPORT sur une chaîne de périodes (P5.5b-1, revue propriétaire). Le report télescope :
 * {@code Σ net = Σ(brut − com − remb) − solde_final}. Une inversion de signe dans le transport du report
 * casse cette égalité — ce que le test à propriétés Σ=0 (identiquement nul) ne peut PAS détecter.
 */
class ReglesReversementChainageTest {

    private static EncaissementImputable enc(long montant) {
        return new EncaissementImputable(UUID.randomUUID(), "F", Instant.parse("2026-01-15T10:00:00Z"), montant);
    }

    private static RemboursementImputable remb(long montant) {
        return new RemboursementImputable(UUID.randomUUID(), "R", Instant.parse("2026-01-20T10:00:00Z"), montant);
    }

    @Test
    @DisplayName("Report chaîné sur 4 périodes (dont un net négatif) → Σnet = Σ(brut−com−remb) − solde_final")
    void conservationDuReport() {
        // Périodes : encaissements et remboursements variés ; taux 250 bps.
        List<List<EncaissementImputable>> enc = List.of(
                List.of(enc(100_000)),
                List.of(enc(10_000)),            // sera dépassé par le remboursement → net 0, report négatif
                List.of(enc(50_000)),
                List.of(enc(30_000)));
        List<List<RemboursementImputable>> rem = List.of(
                List.of(),
                List.of(remb(40_000)),
                List.of(),
                List.of());

        long sommeNet = 0;
        long sommeBrutMoinsComMoinsRemb = 0;
        long report = 0;
        long soldeFinal = 0;
        for (int k = 0; k < enc.size(); k++) {
            ResultatReversement r = ReglesReversement.calculer(enc.get(k), rem.get(k), 250, report);
            sommeNet += r.montantNetAReverser();
            sommeBrutMoinsComMoinsRemb += r.montantBrutDu() - r.montantCommission() - r.montantRembourse();
            report = r.soldeReporte();   // devient le report de la période suivante
            soldeFinal = r.soldeReporte();
        }

        assertThat(sommeNet).isEqualTo(sommeBrutMoinsComMoinsRemb - soldeFinal);
    }
}
