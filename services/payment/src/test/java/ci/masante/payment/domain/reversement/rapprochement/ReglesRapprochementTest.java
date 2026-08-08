package ci.masante.payment.domain.reversement.rapprochement;

import ci.masante.payment.domain.integrity.Severite;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.time.temporal.ChronoUnit;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Règles PURES du rapprochement « factures ↔ reversements » (P5.5c) — frontière (CDC_01 §0.1). Prouve
 * que chaque écart est DÉTECTÉ ET que le sain reste vert. Test pur, exécuté au build (G3) : la
 * concurrence, l'idempotence et le balayage 2 sources se prouvent en G2 live.
 */
class ReglesRapprochementTest {

    private final ReglesRapprochement regles = new ReglesRapprochement();
    private final Instant maintenant = Instant.parse("2026-08-08T00:00:00Z");
    private final Instant seuilGrace = maintenant.minus(2, ChronoUnit.DAYS);

    // ── A → B : complétude (PIECE_NON_REVERSEE) ─────────────────────────────────────────────────

    @Test
    @DisplayName("Pièce soldée avant le seuil de grâce, montant > 0 → PIECE_NON_REVERSEE")
    void pieceDueNonReversee() {
        Instant vieux = seuilGrace.minus(1, ChronoUnit.DAYS);
        assertThat(regles.pieceNonReversee("FACTURE", "F-1", vieux, seuilGrace, 6000))
                .get()
                .satisfies(e -> {
                    assertThat(e.type()).isEqualTo(TypeEcartRapprochement.PIECE_NON_REVERSEE);
                    assertThat(e.severite()).isEqualTo(Severite.MAJEUR);
                    assertThat(e.montantAttendu()).isEqualTo(6000L);
                });
    }

    @Test
    @DisplayName("Pièce soldée DANS le délai de grâce → aucun écart (relevé pas encore dû)")
    void pieceDansDelaiDeGrace() {
        Instant recent = seuilGrace.plus(1, ChronoUnit.HOURS);
        assertThat(regles.pieceNonReversee("FACTURE", "F-2", recent, seuilGrace, 6000)).isEmpty();
    }

    @Test
    @DisplayName("Pièce soldée exactement au seuil (borne stricte) → aucun écart")
    void pieceAuSeuilExact() {
        assertThat(regles.pieceNonReversee("FACTURE", "F-3", seuilGrace, seuilGrace, 6000)).isEmpty();
    }

    @Test
    @DisplayName("Montant nul ou date absente → aucun écart (rien de dû)")
    void pieceSansMontantOuDate() {
        Instant vieux = seuilGrace.minus(1, ChronoUnit.DAYS);
        assertThat(regles.pieceNonReversee("FACTURE", "F-4", vieux, seuilGrace, 0)).isEmpty();
        assertThat(regles.pieceNonReversee("FACTURE", "F-5", null, seuilGrace, 6000)).isEmpty();
    }

    // ── B → A : intégrité + montant (REVERSEMENT_SANS_PIECE / MONTANT_REVERSE_DIVERGENT) ─────────

    @Test
    @DisplayName("Ligne cohérente (pièce présente, bon statut, bon établissement, même montant) → aucun écart")
    void ligneSaine() {
        assertThat(regles.evaluerLigne("F-10", 8000, "PAYEE", "PAYEE", "ETAB-1", "ETAB-1", 8000L)).isEmpty();
    }

    @Test
    @DisplayName("Pièce introuvable → REVERSEMENT_SANS_PIECE (orphelin, prime sur le montant)")
    void ligneOrphelinePieceAbsente() {
        assertThat(regles.evaluerLigne("F-11", 5000, null, "PAYEE", null, "ETAB-1", null))
                .get().extracting(EcartRapprochement::type)
                .isEqualTo(TypeEcartRapprochement.REVERSEMENT_SANS_PIECE);
    }

    @Test
    @DisplayName("Pièce dans un statut inattendu → REVERSEMENT_SANS_PIECE")
    void ligneStatutInattendu() {
        assertThat(regles.evaluerLigne("F-12", 5000, "REMPLACEE", "PAYEE", "ETAB-1", "ETAB-1", 5000L))
                .get().extracting(EcartRapprochement::type)
                .isEqualTo(TypeEcartRapprochement.REVERSEMENT_SANS_PIECE);
    }

    @Test
    @DisplayName("Établissement divergent → REVERSEMENT_SANS_PIECE (prime sur un montant pourtant égal)")
    void ligneEtablissementDivergent() {
        assertThat(regles.evaluerLigne("F-13", 5000, "PAYEE", "PAYEE", "ETAB-AUTRE", "ETAB-1", 5000L))
                .get().extracting(EcartRapprochement::type)
                .isEqualTo(TypeEcartRapprochement.REVERSEMENT_SANS_PIECE);
    }

    @Test
    @DisplayName("Pièce valide mais montant imputé ≠ montant courant → MONTANT_REVERSE_DIVERGENT")
    void ligneMontantDivergent() {
        assertThat(regles.evaluerLigne("F-14", 7000, "PAYEE", "PAYEE", "ETAB-1", "ETAB-1", 10000L))
                .get()
                .satisfies(e -> {
                    assertThat(e.type()).isEqualTo(TypeEcartRapprochement.MONTANT_REVERSE_DIVERGENT);
                    assertThat(e.severite()).isEqualTo(Severite.CRITIQUE);
                    assertThat(e.montantAttendu()).isEqualTo(10000L);   // montant courant côté facture
                    assertThat(e.montantConstate()).isEqualTo(7000L);   // montant imputé côté reversement
                });
    }

    @Test
    @DisplayName("Remboursement valide et concordant (statut REUSSI) → aucun écart")
    void ligneRemboursementSaine() {
        assertThat(regles.evaluerLigne("R-20", 3000, "REUSSI", "REUSSI", "ETAB-1", "ETAB-1", 3000L)).isEmpty();
    }
}
