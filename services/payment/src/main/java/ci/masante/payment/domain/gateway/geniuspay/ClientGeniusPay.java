package ci.masante.payment.domain.gateway.geniuspay;

import ci.masante.payment.config.ProprietesGeniusPay;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpMethod;
import org.springframework.http.MediaType;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.stereotype.Component;
import org.springframework.web.client.RestClientException;
import org.springframework.web.client.RestClient;

import java.io.UncheckedIOException;
import java.net.http.HttpClient;
import java.time.Duration;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;

/**
 * Client HTTP du prestataire — <b>le seul</b> endroit qui parle à GeniusPay (D3).
 *
 * <h2>Exemple canonique de la documentation GeniusPay, et pourquoi il ne faut pas le recopier</h2>
 * <pre>
 * $response = Http::withHeaders([
 *     'X-API-Key' =&gt; 'pk_live_xxx',
 *     'X-API-Secret' =&gt; 'sk_live_xxx',
 * ])-&gt;post('https://pay.genius.ci/api/v1/merchant/payments', [
 *     'amount' =&gt; 15000,
 *     'description' =&gt; 'Commande #123',
 * ]);
 * return redirect($response['data']['checkout_url']);
 * </pre>
 * <p><b>C'est une démonstration commerciale, pas un modèle de production.</b> Il lui manque cinq
 * choses sans lesquelles l'intégration ne tient pas : {@code metadata.order_id} (sans quoi aucun
 * webhook ne peut être relié à une facture), {@code success_url}, une échéance, une gestion d'erreur,
 * et une journalisation. On s'en inspire pour le <b>contrat</b> — URL, en-têtes, chemin
 * {@code data.checkout_url} — jamais pour la structure du code.</p>
 *
 * <h2>Pourquoi ce client, et pourquoi HTTP/1.1 explicitement</h2>
 * <p>Le client repose sur {@code HttpClient} du JDK, <b>épinglé en HTTP/1.1</b>. Les deux décisions
 * ont une raison distincte, et toutes deux ont été apprises en éprouvant le code.</p>
 * <p>L'épinglage d'abord : laissé libre, le client négocie HTTP/2 et tente au besoin une bascule en
 * clair (h2c) que certains serveurs acceptent en <b>perdant le corps de la requête</b> — le défaut a
 * déjà coûté une séance de diagnostic sur ce projet (ADR-020).</p>
 * <p>Le choix du client ensuite. {@code SimpleClientHttpRequestFactory}, écrit sur
 * {@code HttpURLConnection}, semblait plus simple — il ne parle que HTTP/1.1, l'épinglage y est
 * gratuit. Mais il traite <b>401 à part</b> : la couche d'authentification du JDK s'interpose, le
 * corps d'erreur devient illisible, et le refus remonte comme une panne de transport. Conséquence en
 * production : un jeu de clés invalide aurait laissé les transactions en <b>incertitude</b> — donc
 * sans rejeu, donc bloquées — au lieu d'échouer franchement. Le test qui oppose « injoignable » et
 * « refusé » l'a attrapé.</p>
 *
 * <h2>Ce qui n'est jamais journalisé</h2>
 * <p>Ni le corps envoyé, ni le corps reçu, ni les en-têtes d'authentification, ni le numéro du
 * payeur. La trace porte la méthode, le chemin, la référence interne, le code HTTP et la durée —
 * de quoi diagnostiquer, rien de quoi fuiter.</p>
 */
@Component
public class ClientGeniusPay {

    private static final Logger log = LoggerFactory.getLogger(ClientGeniusPay.class);

    private static final String CHEMIN_PAIEMENTS = "/api/v1/merchant/payments";
    private static final String CHEMIN_WEBHOOKS = "/api/v1/merchant/webhooks";

    private final RestClient http;
    private final ObjectMapper json;
    private final String baseUrl;

    public ClientGeniusPay(ProprietesGeniusPay proprietes, ObjectMapper json) {
        this.json = json;
        this.baseUrl = proprietes.getBaseUrl();
        // HTTP/1.1 FORCÉ, explicitement. Le client du JDK négocie HTTP/2 par défaut et tente au
        // besoin une bascule en clair (h2c) que certains serveurs acceptent en PERDANT LE CORPS de la
        // requête — le défaut a déjà coûté une séance de diagnostic sur ce projet (ADR-020). Un POST
        // de paiement dont le corps disparaît est exactement l'incident qu'on ne veut pas avoir.
        HttpClient jdk = HttpClient.newBuilder()
                .version(HttpClient.Version.HTTP_1_1)
                .connectTimeout(Duration.ofMillis(proprietes.getTimeoutConnexionMs()))
                .build();
        JdkClientHttpRequestFactory fabrique = new JdkClientHttpRequestFactory(jdk);
        fabrique.setReadTimeout(Duration.ofMillis(proprietes.getTimeoutLectureMs()));
        this.http = RestClient.builder()
                .baseUrl(proprietes.getBaseUrl())
                .requestFactory(fabrique)
                .build();
    }

    /**
     * Crée un paiement. <b>Appelé une fois et une seule</b> : aucun rejeu, aucune boucle, aucun
     * {@code @Retryable} — voir {@code ServiceGeniusPay}. Une panne réseau remonte en
     * {@link GeniusPayInjoignableException}, jamais en succès ni en échec définitif.
     */
    public ReponsesGeniusPay.Paiement creerPaiement(String clePublique, String cleSecrete,
                                                    Map<String, Object> corps, String referenceInterne) {
        return appeler("POST", CHEMIN_PAIEMENTS, clePublique, cleSecrete, corps, referenceInterne);
    }

    /** Consultation par référence prestataire (réconciliation, §8.5). */
    public Optional<ReponsesGeniusPay.Paiement> consulter(String clePublique, String cleSecrete,
                                                          String referencePasserelle) {
        try {
            return Optional.of(appeler("GET", CHEMIN_PAIEMENTS + "/" + referencePasserelle,
                    clePublique, cleSecrete, null, referencePasserelle));
        } catch (GeniusPayException e) {
            if (e.getStatutHttp() == 404) {
                return Optional.empty();
            }
            throw e;
        }
    }

    /**
     * Liste paginée, utilisée par le balayage de levée d'incertitude (§7.4.b).
     *
     * <p><b>Limite constatée en bac à sable</b> : cet endpoint répond {@code 500} à chaque appel, avec
     * ou sans paramètres. Le chemin est donc écrit conformément au contrat et éprouvé par simulation,
     * mais il n'a pas pu être prouvé contre le prestataire. Tant qu'il reste en panne, la levée
     * d'incertitude repose sur le webhook seul, et l'échéance d'abandon fait le reste — c'est-à-dire
     * qu'aucune facture n'est soldée sur une hypothèse, ce qui était déjà la règle.</p>
     */
    public List<ReponsesGeniusPay.Paiement> lister(String clePublique, String cleSecrete,
                                                   String depuis, String jusqua, int parPage) {
        String chemin = CHEMIN_PAIEMENTS + "?per_page=" + parPage
                + (depuis != null ? "&from=" + depuis : "")
                + (jusqua != null ? "&to=" + jusqua : "");
        JsonNode racine = appelerBrut("GET", chemin, clePublique, cleSecrete, null, "liste");
        JsonNode data = racine.path("data");
        // La forme paginée de Laravel imbrique la collection sous data.data ; la forme plate la met
        // directement sous data. On accepte les deux plutôt que de parier sur l'une.
        JsonNode collection = data.isArray() ? data : data.path("data");
        if (!collection.isArray()) {
            return List.of();
        }
        return json.convertValue(collection,
                json.getTypeFactory().constructCollectionType(List.class, ReponsesGeniusPay.Paiement.class));
    }

    /**
     * Crée le webhook du marchand et renvoie son secret. <b>Le secret n'est renvoyé qu'ici, une seule
     * fois</b> : le perdre impose de supprimer le webhook et d'en recréer un. C'est la raison pour
     * laquelle il est chiffré et persisté dans la foulée, jamais affiché.
     */
    public ReponsesGeniusPay.Webhook creerWebhook(String clePublique, String cleSecrete,
                                                  String url, List<String> evenements) {
        Map<String, Object> corps = new LinkedHashMap<>();
        corps.put("url", url);
        corps.put("events", evenements);
        JsonNode racine = appelerBrut("POST", CHEMIN_WEBHOOKS, clePublique, cleSecrete, corps, "webhook");
        return json.convertValue(racine.path("data"), ReponsesGeniusPay.Webhook.class);
    }

    /**
     * Supprime un webhook. Appele en fin de session de démonstration : une URL de tunnel abandonnee
     * qui pointe vers la machine de quelqu'un d'autre est une fuite de données, pas un oubli bénin.
     */
    public void supprimerWebhook(String clePublique, String cleSecrete, String webhookId) {
        appelerBrut("DELETE", CHEMIN_WEBHOOKS + "/" + webhookId, clePublique, cleSecrete, null, webhookId);
    }

    private ReponsesGeniusPay.Paiement appeler(String methode, String chemin, String clePublique,
                                               String cleSecrete, Map<String, Object> corps, String reference) {
        JsonNode racine = appelerBrut(methode, chemin, clePublique, cleSecrete, corps, reference);
        return json.convertValue(racine.path("data"), ReponsesGeniusPay.Paiement.class);
    }

    /**
     * Exécution de l'appel.
     *
     * <p><b>{@code exchange(...)} et non {@code retrieve()}</b>, et ce n'est pas un détail de style.
     * {@code retrieve()} confie la lecture du corps aux convertisseurs de messages et à un gestionnaire
     * de statut ; les deux se sont révélés fragiles ici — un corps d'erreur lu deux fois, un type de
     * contenu inattendu, et l'échec remonte en {@code RestClientException} opaque, sans le code
     * d'erreur du prestataire. Avec {@code exchange}, on lit <b>les octets</b>, on lit <b>le statut</b>,
     * et on décide nous-mêmes. C'est aussi ce qui garantit que le code d'erreur GeniusPay est
     * réellement extrait au lieu d'être noyé dans une exception de conversion.</p>
     *
     * <p>Toute défaillance de transport devient une {@link GeniusPayInjoignableException}, donc une
     * <b>incertitude</b>. C'est le sens sûr : une incertitude ne rejoue jamais, là où une erreur mal
     * classée en refus définitif ferait abandonner une transaction peut-être créée.</p>
     */
    private JsonNode appelerBrut(String methode, String chemin, String clePublique, String cleSecrete,
                                 Map<String, Object> corps, String reference) {
        long debut = System.nanoTime();
        Echange echange;
        try {
            RestClient.RequestBodySpec requete = http.method(HttpMethod.valueOf(methode))
                    .uri(chemin)
                    .header("X-API-Key", clePublique)
                    .header("X-API-Secret", cleSecrete)
                    .header(HttpHeaders.ACCEPT, MediaType.APPLICATION_JSON_VALUE);
            if (corps != null) {
                requete.contentType(MediaType.APPLICATION_JSON).body(corps);
            }
            echange = requete.exchange((req, res) -> {
                // L'ORDRE COMPTE, et il coûte cher à retrouver. `HttpURLConnection.getErrorStream()`
                // renvoie `null` tant que le code de réponse n'a pas été lu : demander le corps avant
                // le statut fait donc échouer la lecture sur toute réponse d'erreur — et le code
                // d'erreur du prestataire, qui vit dans ce corps, serait perdu. Statut d'abord.
                int statut = res.getStatusCode().value();
                byte[] octets = res.getBody() == null ? new byte[0] : res.getBody().readAllBytes();
                return new Echange(statut, new String(octets, StandardCharsets.UTF_8));
            });
        } catch (RestClientException | UncheckedIOException e) {
            long dureeMs = (System.nanoTime() - debut) / 1_000_000;
            log.warn("GeniusPay {} {} ref={} INJOIGNABLE après {}ms : {}", methode, chemin, reference,
                    dureeMs, e.getClass().getSimpleName());
            throw new GeniusPayInjoignableException(
                    "GeniusPay injoignable sur " + methode + " " + chemin, e);
        }

        long dureeMs = (System.nanoTime() - debut) / 1_000_000;
        log.info("GeniusPay {} {} ref={} statut={} duree={}ms", methode, chemin, reference,
                echange.statut(), dureeMs);

        if (echange.statut() < 200 || echange.statut() >= 300) {
            throw new GeniusPayException(codeErreur(echange.corps()), echange.statut(),
                    "GeniusPay a refusé " + methode + " " + chemin + " (HTTP " + echange.statut() + ").");
        }
        if (echange.corps() == null || echange.corps().isBlank()) {
            return json.createObjectNode();
        }
        try {
            return json.readTree(echange.corps());
        } catch (Exception e) {
            throw new GeniusPayException("REPONSE_ILLISIBLE", echange.statut(),
                    "Réponse GeniusPay illisible sur " + methode + " " + chemin + ".");
        }
    }

    /** Statut et octets bruts, avant toute interprétation. */
    private record Echange(int statut, String corps) {
    }

    /** Extrait {@code error.code} sans jamais renvoyer le corps : il peut porter des données du marchand. */
    private String codeErreur(String texte) {
        if (texte == null || texte.isBlank()) {
            return "INCONNU";
        }
        try {
            JsonNode n = json.readTree(texte);
            String code = n.path("error").path("code").asText(null);
            if (code == null || code.isBlank()) {
                code = n.path("code").asText(null);
            }
            return code == null || code.isBlank() ? "INCONNU" : code;
        } catch (Exception e) {
            return "INCONNU";
        }
    }

    /**
     * Convertit un montant du prestataire en francs entiers. {@code "amount": 10000.00} est licite,
     * {@code 10000.50} ne l'est pas : le XOF n'a pas de sous-unité, une décimale non nulle est une
     * <b>anomalie bloquante</b> et non un arrondi à faire.
     */
    public static long enFrancsEntiers(BigDecimal valeur) {
        if (valeur == null) {
            throw new IllegalArgumentException("Montant absent de la réponse du prestataire.");
        }
        BigDecimal net = valeur.stripTrailingZeros();
        if (net.scale() > 0) {
            throw new MontantNonEntierException(valeur);
        }
        return net.longValueExact();
    }

    public String baseUrl() {
        return baseUrl;
    }
}
