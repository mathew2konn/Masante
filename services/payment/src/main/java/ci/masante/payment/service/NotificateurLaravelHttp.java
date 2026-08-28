package ci.masante.payment.service;

import ci.masante.payment.domain.notification.NotificationSysteme;
import ci.masante.payment.domain.notification.NotificationSystemeException;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.Map;

/**
 * Adaptateur du port {@link NotificationSysteme} vers le backend Laravel de MASANTÉ (lot 6).
 *
 * <p>Transport = {@link java.net.http.HttpClient} du JDK, comme {@link ClientFraudeDetection} — aucune
 * dépendance nouvelle. <b>HTTP/1.1 forcé</b> pour la même raison qu'ailleurs dans ce service : le
 * client JDK tente par défaut une négociation HTTP/2 en clair (h2c) que tous les serveurs ne gèrent
 * pas, et le corps de la requête se perd pendant l'upgrade — panne silencieuse côté émetteur.</p>
 *
 * <p>Délais de connexion ET de lecture explicites : un client sans délai attend indéfiniment, et
 * c'est le relais entier qui se bloquerait sur une seule ligne d'outbox.</p>
 *
 * <p>Tout ce qui n'est pas un 2xx lève : la ligne d'outbox passe ECHOUEE avec un motif lisible, et
 * la politique de rejeu déjà en place s'applique. Aucune politique nouvelle n'est inventée ici.</p>
 */
@Component
public class NotificateurLaravelHttp implements NotificationSysteme {

    /** Chemin vérifié côté Laravel — c'est LUI qui est lié dans la signature, sans chaîne de requête. */
    static final String CHEMIN = "/api/interne/v1/paiements/notification";

    private final String baseUrl;
    private final SigneurPrincipalSortant signeur;
    private final HttpClient http;
    private final Duration timeoutLecture;

    public NotificateurLaravelHttp(
            @Value("${masante.payment.laravel.base-url:http://host.docker.internal:8000}") String baseUrl,
            @Value("${masante.payment.laravel.timeout-connexion-s:5}") long timeoutConnexionS,
            @Value("${masante.payment.laravel.timeout-lecture-s:10}") long timeoutLectureS,
            SigneurPrincipalSortant signeur) {
        this.baseUrl = baseUrl.replaceAll("/+$", "");
        this.signeur = signeur;
        this.timeoutLecture = Duration.ofSeconds(timeoutLectureS);
        this.http = HttpClient.newBuilder()
                .version(HttpClient.Version.HTTP_1_1)
                .connectTimeout(Duration.ofSeconds(timeoutConnexionS))
                .build();
    }

    @Override
    public void livrer(String chargeJson) {
        Map<String, String> entetes;
        try {
            entetes = signeur.signer("POST", CHEMIN);
        } catch (IllegalStateException e) {
            // Secret absent : on ne fabrique pas un appel invérifiable, on échoue en le disant.
            throw new NotificationSystemeException(e.getMessage(), e);
        }

        HttpRequest.Builder builder = HttpRequest.newBuilder(URI.create(baseUrl + CHEMIN))
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")
                .timeout(timeoutLecture)
                .POST(HttpRequest.BodyPublishers.ofString(chargeJson, StandardCharsets.UTF_8));
        entetes.forEach(builder::header);

        HttpResponse<String> reponse;
        try {
            reponse = http.send(builder.build(), HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
        } catch (IOException e) {
            throw new NotificationSystemeException("Backend Laravel injoignable : " + e.getMessage(), e);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new NotificationSystemeException("Notification vers Laravel interrompue.", e);
        }

        int code = reponse.statusCode();
        if (code < 200 || code >= 300) {
            // Le corps n'est PAS repris dans le motif : un 401 générique n'a rien à apprendre, et une
            // page d'erreur HTML polluerait la colonne `detail` de l'outbox.
            throw new NotificationSystemeException("Laravel a répondu HTTP " + code + ".");
        }
    }
}
