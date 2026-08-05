package ci.masante.payment.domain.integrity;

import java.util.Map;

/**
 * Un écart détecté par un contrôle. Valeur PURE : le service décide de le persister (table
 * {@code controle_ecarts}) et de sérialiser {@code details} en JSONB. Aucune correction n'y est
 * attachée — la détection ne corrige jamais (CDC_06 §11).
 *
 * @param montantAttendu FCFA (entier) attendu — {@code null} si non pertinent.
 * @param montantConstate FCFA (entier) réellement constaté.
 * @param details snapshot d'explication (rejouable), sérialisé en JSONB.
 */
public record Ecart(TypeControle controle, TypeEcart type, Severite severite, String reference,
                    Long montantAttendu, Long montantConstate, Map<String, Object> details) {
}
