package ci.masante.payment.service;

import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.data.redis.core.StringRedisTemplate;
import org.springframework.data.redis.core.ValueOperations;
import org.springframework.test.util.ReflectionTestUtils;

import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.Base64;
import java.util.Map;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

/**
 * Lot 6 — le signeur sortant produit exactement ce que la vérification entrante accepte.
 *
 * <p><b>Test CROISÉ, et c'est tout son intérêt</b> : il ne compare pas le signeur à une signature
 * de référence recopiée (qui figerait un bug partagé), il le confronte au vérificateur RÉEL du
 * projet, {@link ServicePrincipal}. Si l'un des deux dérive — ordre des claims, encodage, portée du
 * HMAC, liaison method/path — ce fichier tombe.</p>
 */
class SigneurPrincipalSortantTest {

    private static final String SECRET_B64 = "cHJpbmNpcGFsLWRldi1zZWNyZXQtMDEyMzQ1Njc4OSEh";
    private static final String CHEMIN = "/api/interne/v1/paiements/notification";

    private SigneurPrincipalSortant signeur;
    private ServicePrincipal verificateur;

    @BeforeEach
    @SuppressWarnings("unchecked") // mock d'un ValueOperations<String, String> : générique effacé
    void setup() {
        ObjectMapper json = new ObjectMapper();

        signeur = new SigneurPrincipalSortant(json, SECRET_B64, "paiement-service", "SYSTEME");
        ReflectionTestUtils.invokeMethod(signeur, "init");

        StringRedisTemplate redis = mock(StringRedisTemplate.class);
        ValueOperations<String, String> ops = mock(ValueOperations.class);
        when(redis.opsForValue()).thenReturn(ops);
        // Nonce toujours neuf : ce test porte sur la signature, pas sur l'anti-rejeu.
        when(ops.setIfAbsent(anyString(), anyString(), any(Duration.class))).thenReturn(true);

        verificateur = new ServicePrincipal(json, redis, SECRET_B64, false);
        ReflectionTestUtils.invokeMethod(verificateur, "init");
    }

    // ── 2. La signature sortante est vérifiable par le vérificateur réel ───────────────────

    @Test
    @DisplayName("ServicePrincipal accepte un principal produit par le signeur")
    void test_signature_sortante_identique_a_ce_que_service_principal_verifie() {
        Map<String, String> entetes = signeur.signer("POST", CHEMIN);

        ServicePrincipal.PrincipalAuthentifie principal = verificateur.verifier(
                entetes.get("X-Principal"), entetes.get("X-Principal-Sig"), "POST", CHEMIN);

        assertThat(principal.sub()).isEqualTo("paiement-service");
        assertThat(principal.roles()).containsExactly("SYSTEME");
    }

    @Test
    @DisplayName("La signature est LIÉE au chemin : la rejouer ailleurs est refusé")
    void liaisonAuChemin() {
        Map<String, String> entetes = signeur.signer("POST", CHEMIN);

        assertThatThrownBy(() -> verificateur.verifier(
                entetes.get("X-Principal"), entetes.get("X-Principal-Sig"), "POST", "/api/interne/v1/autre-chose"))
                .isInstanceOf(PrincipalInvalideException.class);
    }

    @Test
    @DisplayName("La signature est LIÉE à la méthode")
    void liaisonALaMethode() {
        Map<String, String> entetes = signeur.signer("POST", CHEMIN);

        assertThatThrownBy(() -> verificateur.verifier(
                entetes.get("X-Principal"), entetes.get("X-Principal-Sig"), "GET", CHEMIN))
                .isInstanceOf(PrincipalInvalideException.class);
    }

    @Test
    @DisplayName("Un second secret ferait diverger les deux sens du canal — et le vérificateur refuse")
    void secretDifferentRefuse() {
        SigneurPrincipalSortant autre = new SigneurPrincipalSortant(
                new ObjectMapper(), "YXV0cmUtc2VjcmV0LXF1aS1uZS1kb2l0LXBhcy1tYXJjaGVy", "x", "SYSTEME");
        ReflectionTestUtils.invokeMethod(autre, "init");

        Map<String, String> entetes = autre.signer("POST", CHEMIN);

        assertThatThrownBy(() -> verificateur.verifier(
                entetes.get("X-Principal"), entetes.get("X-Principal-Sig"), "POST", CHEMIN))
                .isInstanceOf(PrincipalInvalideException.class);
    }

    @Test
    @DisplayName("Secret absent : on refuse de signer plutôt que d'émettre un appel invérifiable")
    void secretAbsentRefuseDeSigner() {
        SigneurPrincipalSortant sansSecret = new SigneurPrincipalSortant(new ObjectMapper(), "", "x", "SYSTEME");
        ReflectionTestUtils.invokeMethod(sansSecret, "init");

        assertThat(sansSecret.peutSigner()).isFalse();
        assertThatThrownBy(() -> sansSecret.signer("POST", CHEMIN))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("MASANTE_PAYMENT_PRINCIPAL_SECRET");
    }

    @Test
    @DisplayName("Le secret n'apparaît jamais dans toString()")
    void toStringNExposeRien() {
        assertThat(signeur.toString()).doesNotContain(SECRET_B64).doesNotContain("secret=");
    }

    // ── VECTEUR PARTAGÉ Java ⇄ PHP ─────────────────────────────────────────────────────────

    /**
     * Le test croisé ci-dessus prouve Java ⇄ Java ; la suite Laravel prouve PHP ⇄ PHP. Il manquait
     * le seul segment qui compte en production : <b>Java signe, PHP vérifie</b>.
     *
     * <p>Ce vecteur le ferme sans harnais inter-langages : la même paire (chaîne à signer → HMAC
     * attendu) est asserté ici ET dans {@code CanalInternePaiementTest} côté Laravel. Si l'une des
     * deux implémentations dérive — encodage du secret, portée du HMAC, base64 —, son test tombe
     * seul, et l'on sait immédiatement lequel des deux côtés a bougé. Même motif que les vecteurs
     * partagés du NIS (P6.1), pour la même raison : une garantie inter-langages ne tient pas si
     * chaque langage se relit lui-même.</p>
     *
     * <p>Seule la SIGNATURE est figée, jamais une vérification complète : les claims portent des
     * horodatages, et un vecteur figé qui les traverserait échouerait le jour où on le rejoue.</p>
     */
    @Test
    @DisplayName("Vecteur partagé : le HMAC de cette chaîne exacte vaut cette valeur exacte")
    void vecteurPartageAvecLaravel() {
        String principalB64 = "eyJzdWIiOiJwYWllbWVudC1zZXJ2aWNlIiwicm9sZXMiOlsiU1lTVEVNRSJdLCJpYXQi"
                + "OjE3OTgwMDAwMDAsImV4cCI6MTc5ODAwMDEyMCwibWV0aG9kIjoiUE9TVCIsInBhdGgiOiIvYXBpL2lu"
                + "dGVybmUvdjEvcGFpZW1lbnRzL25vdGlmaWNhdGlvbiIsIm5vbmNlIjoiMTExMTExMTEtMjIyMi0zMzMz"
                + "LTQ0NDQtNTU1NTU1NTU1NTU1In0=";
        String signatureAttendue = "duiZUC7woP0XOLmmyKvU+lQHQ6iUbzKR5Jt6ZExvStg=";

        String signature = Base64.getEncoder().encodeToString(
                ReflectionTestUtils.invokeMethod(signeur, "hmac",
                        (Object) principalB64.getBytes(StandardCharsets.UTF_8)));

        assertThat(signature).isEqualTo(signatureAttendue);
    }
}
