package ci.masante.payment.domain.reversement.rapprochement;

import ci.masante.payment.domain.integrity.Severite;

import java.time.Instant;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Objects;
import java.util.Optional;

/**
 * Règles PURES du rapprochement à deux sources « factures ↔ reversements » (P5.5c, CDC_06 §11). Aucune
 * I/O : chaque méthode reçoit des faits déjà lus (bornés au cut-off / au délai de grâce par la requête)
 * et décide s'ils constituent un écart. Frontière (CDC_01 §0.1) : tout le JUGEMENT est ici, jamais dans
 * un contrôleur → testable unitairement.
 *
 * <h2>Monnaie</h2>
 * FCFA (XOF) sans sous-unité : montants entiers ({@code long}). Aucun arrondi n'est effectué ici — on
 * compare des valeurs déjà arrondies par les sous-systèmes source.
 *
 * <h2>Directionnalité</h2>
 * <ul>
 *   <li><b>A → B</b> (complétude) : une pièce due côté facturation doit finir dans un relevé
 *       ({@link #pieceNonReversee}). Un <b>délai de grâce</b> (donnée) évite de signaler une pièce
 *       fraîchement soldée dont le relevé périodique n'a légitimement pas encore tourné.</li>
 *   <li><b>B → A</b> (intégrité + montant) : une ligne active doit référencer une pièce réelle, valide,
 *       du bon établissement, au bon montant ({@link #evaluerLigne}). L'orphelin prime sur la
 *       divergence de montant (une pièce absente n'a pas de montant à comparer).</li>
 * </ul>
 */
public final class ReglesRapprochement {

    /**
     * Complétude A → B. La pièce (facture {@code PAYEE} ou remboursement {@code REUSSI}) a été retenue
     * par la requête comme imputable et non imputée ; la règle confirme qu'elle est bien soldée AVANT le
     * seuil de grâce et porte un montant positif (défense en profondeur, rejouable et testable).
     *
     * @param typePiece    "FACTURE" ou "REMBOURSEMENT" (trace d'explication).
     * @param dateeA       date d'imputation immuable de la pièce ({@code soldee_a} / {@code cree_le}).
     * @param seuilGrace   cut-off T diminué du délai de grâce : {@code dateeA} doit lui être antérieure.
     */
    public Optional<EcartRapprochement> pieceNonReversee(String typePiece, String reference,
                                                         Instant dateeA, Instant seuilGrace, long montant) {
        if (dateeA == null || !dateeA.isBefore(seuilGrace) || montant <= 0) {
            return Optional.empty(); // dans le délai de grâce ou montant non dû → pas encore un écart
        }
        return Optional.of(new EcartRapprochement(TypeEcartRapprochement.PIECE_NON_REVERSEE,
                Severite.MAJEUR, reference, montant, 0L,
                details("typePiece", typePiece, "dateeA", dateeA.toString(), "seuilGrace", seuilGrace.toString())));
    }

    /**
     * Intégrité + montant B → A d'une ligne active. Au plus UN écart par ligne : l'orphelin
     * ({@link TypeEcartRapprochement#REVERSEMENT_SANS_PIECE}) prime sur la divergence de montant.
     *
     * @param reference      référence de la pièce portée par la ligne.
     * @param montantImpute  montant imputé par la ligne (source B).
     * @param pieceStatut    statut COURANT de la pièce côté facturation, ou {@code null} si introuvable.
     * @param statutAttendu  statut valide attendu ("PAYEE" pour une facture, "REUSSI" pour un remboursement).
     * @param pieceEtab      établissement COURANT de la pièce, ou {@code null} si introuvable.
     * @param releveEtab     établissement du relevé qui porte la ligne.
     * @param montantCourant montant COURANT de la pièce (source A), ou {@code null} si introuvable.
     */
    public Optional<EcartRapprochement> evaluerLigne(String reference, long montantImpute,
                                                     String pieceStatut, String statutAttendu,
                                                     String pieceEtab, String releveEtab, Long montantCourant) {
        boolean orpheline = pieceStatut == null
                || !pieceStatut.equals(statutAttendu)
                || pieceEtab == null
                || !pieceEtab.equals(releveEtab);
        if (orpheline) {
            return Optional.of(new EcartRapprochement(TypeEcartRapprochement.REVERSEMENT_SANS_PIECE,
                    Severite.CRITIQUE, reference, montantImpute,
                    montantCourant, // peut être null (pièce absente)
                    details("pieceStatut", pieceStatut, "statutAttendu", statutAttendu,
                            "pieceEtab", pieceEtab, "releveEtab", releveEtab)));
        }
        if (!Objects.equals(montantCourant, montantImpute)) {
            return Optional.of(new EcartRapprochement(TypeEcartRapprochement.MONTANT_REVERSE_DIVERGENT,
                    Severite.CRITIQUE, reference, montantCourant, montantImpute,
                    details("montantImpute", montantImpute, "montantCourant", montantCourant)));
        }
        return Optional.empty();
    }

    /** Petite fabrique de map d'explication (évite les pièges de généricité de {@code Map.of} avec des null). */
    private static Map<String, Object> details(Object... kv) {
        Map<String, Object> m = new LinkedHashMap<>();
        for (int i = 0; i + 1 < kv.length; i += 2) {
            m.put(String.valueOf(kv[i]), kv[i + 1]);
        }
        return m;
    }
}
