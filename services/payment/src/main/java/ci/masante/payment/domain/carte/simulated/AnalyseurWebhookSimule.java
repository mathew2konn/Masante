package ci.masante.payment.domain.carte.simulated;

import ci.masante.payment.domain.carte.EvenementWebhook;
import ci.masante.payment.domain.carte.IssuePasserelle;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.time.Instant;

/**
 * Parse le corps JSON d'un webhook SIMULÉ en {@link EvenementWebhook} normalisé. Format attendu :
 * {@code {evenementId, type, refPasserelle, issue, horodatage, ntid?, marque?, last4?, expMois?, expAnnee?,
 * codeRefus?}}. L'{@code horodatage} (ISO-8601) est dans le corps SIGNÉ → lié par le HMAC (anti-rejeu §7.3).
 * Aucune donnée sensible n'est attendue ni conservée (frontière PCI).
 */
final class AnalyseurWebhookSimule {

    private AnalyseurWebhookSimule() {
    }

    static EvenementWebhook parser(ObjectMapper json, byte[] corpsBrut) {
        try {
            JsonNode n = json.readTree(corpsBrut);
            return new EvenementWebhook(
                    texte(n, "evenementId"),
                    texte(n, "type"),
                    texte(n, "refPasserelle"),
                    IssuePasserelle.valueOf(texte(n, "issue")),
                    horodatage(n),
                    texte(n, "ntid"),
                    texte(n, "marque"),
                    texte(n, "last4"),
                    entier(n, "expMois"),
                    entier(n, "expAnnee"),
                    texte(n, "codeRefus"));
        } catch (Exception e) {
            throw new IllegalArgumentException("Webhook illisible ou malformé.", e);
        }
    }

    private static Instant horodatage(JsonNode n) {
        String v = texte(n, "horodatage");
        return v == null ? null : Instant.parse(v);
    }

    private static String texte(JsonNode n, String champ) {
        JsonNode v = n.get(champ);
        return v == null || v.isNull() ? null : v.asText();
    }

    private static Integer entier(JsonNode n, String champ) {
        JsonNode v = n.get(champ);
        return v == null || v.isNull() ? null : v.asInt();
    }
}
