package ci.masante.payment.web;

import ci.masante.payment.service.ServiceGeniusPay;
import ci.masante.payment.service.ServiceMarchandGeniusPay;
import ci.masante.payment.service.ServicePrincipal;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.http.ResponseEntity;
import org.springframework.mock.web.MockHttpServletRequest;

import java.util.Map;
import java.util.Set;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

/**
 * B4 (ADR-056, S7) — {@code GET /marchands/{etablissementRef}} : « configuré : oui/non », jamais les
 * clés. Test PUR (Mockito) : pas de contexte web, la méthode du contrôleur est appelée directement,
 * comme {@code ServiceWebhookGeniusPayTest} le fait pour son service.
 */
class GeniusPayControllerTest {

    private ServiceGeniusPay service;
    private ServiceMarchandGeniusPay marchands;
    private ServicePrincipal principal;
    private GeniusPayController controleur;

    @BeforeEach
    void preparer() {
        service = mock(ServiceGeniusPay.class);
        marchands = mock(ServiceMarchandGeniusPay.class);
        principal = mock(ServicePrincipal.class);
        controleur = new GeniusPayController(service, marchands, principal);

        when(principal.verifier(anyString(), anyString(), anyString(), anyString()))
                .thenReturn(new ServicePrincipal.PrincipalAuthentifie("laravel", Set.of("SYSTEME")));
    }

    private MockHttpServletRequest requete() {
        MockHttpServletRequest r = new MockHttpServletRequest();
        r.setMethod("GET");
        r.setRequestURI("/api/v1/interne/geniuspay/marchands/CI-ETS000001");
        return r;
    }

    @Test
    @DisplayName("Établissement configuré → configure:true, et RIEN d'autre que le ref et le booléen")
    void reponseConfigureVraiSansAucuneCle() {
        when(marchands.estConfigure("CI-ETS000001")).thenReturn(true);

        ResponseEntity<Map<String, Object>> reponse = controleur.marchandConfigure(
                "principal", "sig", "CI-ETS000001", requete());

        Map<String, Object> corps = reponse.getBody();
        assertThat(corps).containsExactlyInAnyOrderEntriesOf(
                Map.of("etablissementRef", "CI-ETS000001", "configure", true));
    }

    @Test
    @DisplayName("Établissement non configuré → configure:false")
    void reponseConfigureFaux() {
        when(marchands.estConfigure("CI-ETS000001")).thenReturn(false);

        ResponseEntity<Map<String, Object>> reponse = controleur.marchandConfigure(
                "principal", "sig", "CI-ETS000001", requete());

        assertThat(reponse.getBody().get("configure")).isEqualTo(false);
    }

    @Test
    @DisplayName("La réponse ne cite aucune clé, aucun secret, aucun slug — structurellement")
    void reponseNeContientJamaisDeSecret() {
        when(marchands.estConfigure(anyString())).thenReturn(true);

        Map<String, Object> corps = controleur.marchandConfigure(
                "principal", "sig", "CI-ETS000001", requete()).getBody();

        assertThat(corps.keySet()).containsExactlyInAnyOrder("etablissementRef", "configure");
    }

    @Test
    @DisplayName("Le principal est vérifié AVANT toute lecture — même chemin que les autres endpoints")
    void principalVerifieAvantLecture() {
        when(marchands.estConfigure(anyString())).thenReturn(true);

        controleur.marchandConfigure("principal", "sig", "CI-ETS000001", requete());

        verify(principal).verifier(anyString(), anyString(), anyString(), anyString());
        verify(principal).exigerRole(any(), anyString());
    }
}
