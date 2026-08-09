package ci.masante.payment.service;

import ci.masante.payment.domain.model.AlerteFraudeIa;
import ci.masante.payment.domain.model.NiveauFraudeIa;
import ci.masante.payment.domain.notification.TypeNotification;
import ci.masante.payment.repository.AlerteFraudeIaRepository;
import ci.masante.payment.repository.RequetesSignauxFraude;
import ci.masante.payment.service.ClientFraudeDetection.ResultatFraudeVue;
import ci.masante.payment.service.ServiceRoutageFraude.RapportRoutage;
import ci.masante.payment.web.dto.SignauxFactureReponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.test.util.ReflectionTestUtils;

import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyMap;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

/**
 * Orchestrateur de routage des alertes de fraude IA (B1). Test PUR (Mockito). Prouve : seules les
 * factures ≥ SUSPECT deviennent des alertes + notifications (NORMAL ignoré) ; le rejeu est idempotent
 * (met à jour sans re-notifier) ; une fraude injoignable propage l'erreur sans rien persister
 * (dégradation honnête, détection seule).
 */
class ServiceRoutageFraudeTest {

    private static final LocalDate JOUR = LocalDate.of(2026, 8, 9);
    private static final Instant CUTOFF = Instant.parse("2026-08-10T00:00:00Z");
    private static final String DEST = "CTRL-FRAUDE-PLATEFORME";

    private RequetesSignauxFraude requetes;
    private ServiceSignauxFraude signaux;
    private ClientFraudeDetection fraude;
    private AlerteFraudeIaRepository alertes;
    private ServiceNotifications notifications;
    private ServiceRoutageFraude service;

    @BeforeEach
    void setup() {
        requetes = mock(RequetesSignauxFraude.class);
        signaux = mock(ServiceSignauxFraude.class);
        fraude = mock(ClientFraudeDetection.class);
        alertes = mock(AlerteFraudeIaRepository.class);
        notifications = mock(ServiceNotifications.class);
        service = new ServiceRoutageFraude(requetes, signaux, fraude, alertes, notifications,
                new ObjectMapper(), 1, 500, DEST, null);
        // save() renvoie l'entité en lui assignant un id (comme le ferait JPA à la persistance).
        when(alertes.save(any(AlerteFraudeIa.class))).thenAnswer(inv -> {
            AlerteFraudeIa a = inv.getArgument(0);
            if (ReflectionTestUtils.getField(a, "id") == null) {
                ReflectionTestUtils.setField(a, "id", UUID.randomUUID());
            }
            return a;
        });
    }

    private SignauxFactureReponse signal(String ref) {
        return new SignauxFactureReponse(ref, "ETB-1", 50000, 35000, 15000, 45000, 35000, 3, 1, 2, 50000, 2, 10, 25);
    }

    @Test
    @DisplayName("Seules les factures ≥ SUSPECT deviennent alerte + notification ; NORMAL est ignoré")
    void suspectCreeAlerteEtNotifie() {
        List<ResultatFraudeVue> vues = List.of(
                new ResultatFraudeVue("FCT-SAINE", "NORMAL", 0, "hybride", "[]", "[]"),
                new ResultatFraudeVue("FCT-SUSP", "TRES_SUSPECT", 80, "hybride", "[{\"code\":\"X\"}]", "[]"));
        Map<String, SignauxFactureReponse> parRef = Map.of(
                "FCT-SAINE", signal("FCT-SAINE"), "FCT-SUSP", signal("FCT-SUSP"));
        when(alertes.findByFactureRefAndDateRapport(any(), eq(JOUR))).thenReturn(Optional.empty());

        RapportRoutage r = service.persister(JOUR, CUTOFF, vues, parRef);

        assertThat(r.nbEvaluees()).isEqualTo(2);
        assertThat(r.nbSuspectes()).isEqualTo(1);
        assertThat(r.nbNouvelles()).isEqualTo(1);
        assertThat(r.nbNotifiees()).isEqualTo(1);
        // Une seule notification, vers le contrôleur plateforme, de type FRAUDE_SUSPECTEE.
        verify(notifications).emettre(eq(TypeNotification.FRAUDE_SUSPECTEE), eq("ia_fraude_alerte"),
                any(UUID.class), eq(DEST), anyMap());
    }

    @Test
    @DisplayName("Rejeu idempotent : alerte déjà présente → mise à jour, aucune nouvelle notification")
    void rejeuNeReNotifiePas() {
        AlerteFraudeIa existante = new AlerteFraudeIa("FCT-SUSP", "ETB-1", null, JOUR,
                NiveauFraudeIa.SUSPECT, 40, "hybride", "[]", "[]", "{}", CUTOFF);
        when(alertes.findByFactureRefAndDateRapport(eq("FCT-SUSP"), eq(JOUR)))
                .thenReturn(Optional.of(existante));

        List<ResultatFraudeVue> vues = List.of(
                new ResultatFraudeVue("FCT-SUSP", "TRES_SUSPECT", 85, "hybride", "[]", "[]"));

        RapportRoutage r = service.persister(JOUR, CUTOFF, vues, Map.of("FCT-SUSP", signal("FCT-SUSP")));

        assertThat(r.nbNouvelles()).isZero();
        assertThat(r.nbNotifiees()).isZero();
        assertThat(existante.getNiveau()).isEqualTo(NiveauFraudeIa.TRES_SUSPECT); // verdict rafraîchi
        assertThat(existante.getScore()).isEqualTo(85);
        verify(notifications, never()).emettre(any(), any(), any(), any(), anyMap());
    }

    @Test
    @DisplayName("Fraude injoignable → l'erreur se propage, aucune alerte persistée (détection seule honnête)")
    void fraudeInjoignablePropage() {
        when(requetes.numerosFacturesEntre(any(), any(), eq(500))).thenReturn(List.of("FCT-1"));
        when(signaux.extraireLot(eq(List.of("FCT-1")), any())).thenReturn(List.of(signal("FCT-1")));
        when(fraude.scorer(any())).thenThrow(new FraudeInjoignableException("down", null));

        assertThatThrownBy(() -> service.executer(JOUR)).isInstanceOf(FraudeInjoignableException.class);

        verify(alertes, never()).save(any());
        verify(notifications, never()).emettre(any(), any(), any(), any(), anyMap());
    }
}
