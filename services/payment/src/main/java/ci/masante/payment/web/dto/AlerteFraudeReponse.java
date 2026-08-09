package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.AlerteFraudeIa;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Vue d'une alerte de fraude IA pour le contrôleur plateforme (lecture). Les snapshots
 * {@code regles}/{@code facteurs}/{@code signaux} sont ré-exposés en JSON (pas en chaîne échappée) pour
 * que l'écran admin (B2) les affiche tels quels. Aucune donnée de décision : lecture seule.
 */
public record AlerteFraudeReponse(
        UUID id,
        String factureRef,
        String etablissementRef,
        String patientRef,
        LocalDate dateRapport,
        String niveau,
        int score,
        String mode,
        String statut,
        boolean notifiee,
        JsonNode regles,
        JsonNode facteurs,
        JsonNode signaux,
        Instant cutOff,
        Instant createdAt,
        Instant revueAt,
        String revuePar) {

    public static AlerteFraudeReponse de(AlerteFraudeIa a, ObjectMapper json) {
        return new AlerteFraudeReponse(
                a.getId(), a.getFactureRef(), a.getEtablissementRef(), a.getPatientRef(), a.getDateRapport(),
                a.getNiveau().name(), a.getScore(), a.getMode(), a.getStatut().name(), a.isNotifiee(),
                lire(json, a.getRegles()), lire(json, a.getFacteurs()), lire(json, a.getSignaux()),
                a.getCutOff(), a.getCreatedAt(), a.getRevueAt(), a.getRevuePar());
    }

    private static JsonNode lire(ObjectMapper json, String brut) {
        try {
            return json.readTree(brut != null ? brut : "null");
        } catch (com.fasterxml.jackson.core.JsonProcessingException e) {
            return null;
        }
    }
}
