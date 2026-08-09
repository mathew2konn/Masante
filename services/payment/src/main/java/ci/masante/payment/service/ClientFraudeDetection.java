package ci.masante.payment.service;

import ci.masante.payment.web.dto.SignauxFactureReponse;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Client HTTP paiement → fraud-detection-service (CDC_05, B1). Le paiement ORCHESTRE : il fournit les
 * signaux et demande un SCORE. Le fraud-detection-service reste un <b>scoreur passif</b> (ADR-017) :
 * il ne gèle/agit jamais, il note et explique. Nouveauté = 1er appel SORTANT du paiement.
 *
 * <p>Transport = {@link java.net.http.HttpClient} du JDK (aucune dépendance nouvelle) avec
 * {@code BodyPublishers.ofString} : le corps JSON part de façon déterministe (le corps d'un {@code Map}
 * via le convertisseur de {@code RestClient} arrivait VIDE côté FastAPI dans cet environnement).</p>
 *
 * <p>Frontière contrat : le paiement produit les signaux en camelCase ({@link SignauxFactureReponse}) ;
 * la fraude les attend en snake_case → conversion explicite ici (unique point). En cas d'indisponibilité,
 * on lève {@link FraudeInjoignableException} : l'orchestrateur échoue proprement, aucune alerte inventée.</p>
 */
@Component
public class ClientFraudeDetection {

    private final String urlScan;
    private final ObjectMapper json;
    // HTTP/1.1 FORCÉ : par défaut le client JDK tente une négociation HTTP/2 (h2c) qu'uvicorn (serveur
    // de la fraude) ne gère pas → le corps de la requête est perdu pendant l'upgrade et FastAPI répond
    // 422 « body required ». C'était aussi la cause du même symptôme via RestClient (même client JDK).
    private final HttpClient http = HttpClient.newBuilder()
            .version(HttpClient.Version.HTTP_1_1)
            .connectTimeout(Duration.ofSeconds(5)).build();

    public ClientFraudeDetection(
            @Value("${masante.payment.fraude.base-url:http://fraud-detection:8090}") String baseUrl,
            ObjectMapper json) {
        this.urlScan = baseUrl.replaceAll("/+$", "") + "/api/v1/fraud/scan";
        this.json = json;
    }

    /** Score un lot de signaux via {@code POST /api/v1/fraud/scan}. Ordre préservé (i-ème résultat = i-ème signal). */
    public List<ResultatFraudeVue> scorer(List<SignauxFactureReponse> signaux) {
        List<Map<String, Object>> corpsSignaux = signaux.stream().map(ClientFraudeDetection::corps).toList();
        String corpsJson;
        try {
            corpsJson = json.writeValueAsString(Map.of("signaux", corpsSignaux));
        } catch (JsonProcessingException e) {
            throw new FraudeInjoignableException("Sérialisation des signaux impossible : " + e.getMessage(), e);
        }
        HttpRequest requete = HttpRequest.newBuilder(URI.create(urlScan))
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")
                .timeout(Duration.ofSeconds(30))
                .POST(HttpRequest.BodyPublishers.ofString(corpsJson, StandardCharsets.UTF_8))
                .build();
        HttpResponse<String> reponseHttp;
        try {
            reponseHttp = http.send(requete, HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
        } catch (IOException e) {
            throw new FraudeInjoignableException("fraud-detection-service injoignable : " + e.getMessage(), e);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new FraudeInjoignableException("Appel au fraud-detection-service interrompu.", e);
        }
        if (reponseHttp.statusCode() != 200) {
            throw new FraudeInjoignableException(
                    "fraud-detection-service a répondu HTTP " + reponseHttp.statusCode(), null);
        }
        JsonNode reponse;
        try {
            reponse = json.readTree(reponseHttp.body());
        } catch (JsonProcessingException e) {
            throw new FraudeInjoignableException("Réponse du fraud-detection-service illisible.", e);
        }
        if (reponse == null || !reponse.has("resultats")) {
            throw new FraudeInjoignableException("Réponse du fraud-detection-service illisible.", null);
        }
        List<ResultatFraudeVue> vues = new ArrayList<>();
        for (JsonNode r : reponse.get("resultats")) {
            vues.add(new ResultatFraudeVue(
                    texte(r, "reference"),
                    texte(r, "niveau"),
                    r.hasNonNull("score") ? r.get("score").asInt() : 0,
                    texte(r, "mode"),
                    noeud(r, "regles_declenchees"),
                    noeud(r, "facteurs_ml")));
        }
        return vues;
    }

    /** Convertit un vecteur de signaux (camelCase) vers le corps attendu par la fraude (snake_case). */
    private static Map<String, Object> corps(SignauxFactureReponse s) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("reference", s.reference());
        m.put("etablissement_ref", s.etablissementRef());
        m.put("montant_ttc", s.montantTtc());
        m.put("montant_couvert", s.montantCouvert());
        m.put("reste_a_payer", s.resteAPayer());
        m.put("montant_acte", s.montantActe());
        m.put("montant_acte_reference", s.montantActeReference());
        m.put("nb_factures_etablissement_30j", s.nbFacturesEtablissement30j());
        m.put("nb_actes_identiques_jour", s.nbActesIdentiquesJour());
        m.put("nb_remboursements_carte_7j", s.nbRemboursementsCarte7j());
        m.put("montant_cumule_wallet_24h", s.montantCumuleWallet24h());
        m.put("nb_ops_wallet_1h", s.nbOpsWallet1h());
        m.put("heure_operation", s.heureOperation());
        m.put("delai_facture_paiement_minutes", s.delaiFacturePaiementMinutes());
        return m;
    }

    private static String texte(JsonNode n, String champ) {
        return n.hasNonNull(champ) ? n.get(champ).asText() : null;
    }

    /** Sous-arbre JSON (regles/facteurs) en texte, pour snapshot JSONB ; {@code "[]"} si absent. */
    private static String noeud(JsonNode n, String champ) {
        return n.hasNonNull(champ) ? n.get(champ).toString() : "[]";
    }

    /** Vue minimale d'un résultat de scoring : ce que l'orchestrateur persiste. */
    public record ResultatFraudeVue(String reference, String niveau, int score, String mode,
                                    String reglesJson, String facteursJson) {
    }
}
