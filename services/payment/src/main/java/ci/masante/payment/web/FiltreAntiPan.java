package ci.masante.payment.web;

import com.fasterxml.jackson.databind.ObjectMapper;
import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.core.Ordered;
import org.springframework.core.annotation.Order;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ProblemDetail;
import org.springframework.stereotype.Component;
import org.springframework.web.filter.OncePerRequestFilter;

import java.io.IOException;
import java.nio.charset.StandardCharsets;

/**
 * Filtre PCI (§9) : rejette toute requête d'écriture carte dont le CORPS contient un PAN en clair (défense
 * en profondeur, au cas où un client contournerait le SDK de tokenisation). Le corps est inspecté puis
 * remis à disposition intact (via {@link CorpsMisEnCacheRequest}).
 *
 * <p><b>Interdit #7</b> : le corps d'une requête de paiement carte n'est JAMAIS journalisé — en cas de
 * détection, on renvoie un 422 générique SANS logger le contenu.</p>
 */
@Component
@Order(Ordered.HIGHEST_PRECEDENCE)
public class FiltreAntiPan extends OncePerRequestFilter {

    private final ObjectMapper json;

    public FiltreAntiPan(ObjectMapper json) {
        this.json = json;
    }

    @Override
    protected boolean shouldNotFilter(HttpServletRequest requete) {
        String uri = requete.getRequestURI();
        // EXCLU : les webhooks PSP — leur corps signé (HMAC sur octets bruts) ne doit être ni mis en cache
        // ni inspecté ; sa vérité est la signature, pas une heuristique. Il ne contient d'ailleurs pas de PAN.
        boolean cibleCarte = uri != null
                && uri.startsWith("/api/v1/card")
                && !uri.startsWith("/api/v1/card-webhooks");
        String methode = requete.getMethod();
        boolean avecCorps = "POST".equalsIgnoreCase(methode)
                || "PUT".equalsIgnoreCase(methode)
                || "PATCH".equalsIgnoreCase(methode);
        return !(cibleCarte && avecCorps);
    }

    @Override
    protected void doFilterInternal(HttpServletRequest requete, HttpServletResponse reponse,
                                    FilterChain chaine) throws ServletException, IOException {
        CorpsMisEnCacheRequest enveloppe = new CorpsMisEnCacheRequest(requete);
        if (DetecteurPan.contientPan(enveloppe.corpsUtf8())) {
            // Interdit #7 : ne JAMAIS journaliser le corps. Réponse générique, aucune donnée renvoyée.
            ecrire422(reponse);
            return;
        }
        chaine.doFilter(enveloppe, reponse);
    }

    private void ecrire422(HttpServletResponse reponse) throws IOException {
        ProblemDetail pd = ProblemDetail.forStatusAndDetail(HttpStatus.UNPROCESSABLE_ENTITY,
                "Donnée de carte en clair détectée : transmettez un token, jamais un numéro de carte (PAN/CVV).");
        pd.setTitle(HttpStatus.UNPROCESSABLE_ENTITY.getReasonPhrase());
        reponse.setStatus(HttpStatus.UNPROCESSABLE_ENTITY.value());
        reponse.setContentType(MediaType.APPLICATION_PROBLEM_JSON_VALUE);
        reponse.setCharacterEncoding(StandardCharsets.UTF_8.name());
        reponse.getWriter().write(json.writeValueAsString(pd));
    }
}
