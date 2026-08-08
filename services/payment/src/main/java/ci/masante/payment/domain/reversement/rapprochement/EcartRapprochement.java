package ci.masante.payment.domain.reversement.rapprochement;

import ci.masante.payment.domain.integrity.Severite;

import java.util.Map;

/**
 * Un écart détecté par le rapprochement « factures ↔ reversements » (P5.5c). Valeur PURE : le service
 * décide de le persister (JSONB dans {@code reversement_reconciliations.ecarts}). Aucune correction n'y
 * est attachée — la détection ne corrige jamais (CDC_06 §11). Réutilise {@link Severite} du domaine
 * intégrité (vocabulaire commun CRITIQUE/MAJEUR).
 *
 * @param montantAttendu FCFA (entier) attendu — {@code null} si non pertinent.
 * @param montantConstate FCFA (entier) réellement constaté — {@code null} si non pertinent.
 * @param details snapshot d'explication (rejouable), sérialisé en JSONB.
 */
public record EcartRapprochement(TypeEcartRapprochement type, Severite severite, String reference,
                                 Long montantAttendu, Long montantConstate, Map<String, Object> details) {
}
