package ci.masante.payment.service;

import ci.masante.payment.repository.RequetesSignauxFraude;
import ci.masante.payment.repository.projection.ActePrincipalProj;
import ci.masante.payment.repository.projection.SignauxFactureProj;
import ci.masante.payment.web.dto.SignauxFactureReponse;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.util.Optional;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

/**
 * Assemblage des SIGNAUX de facturation (extraction fraude, incrément A). Test PUR (Mockito) : le repo
 * (SQL natif) est mocké → on vérifie la LOGIQUE du service (acte absent, patient absent, délai/heure,
 * clamp), pas les agrégats SQL eux-mêmes (prouvés en G2 live). Aucune décision de fraude ici.
 *
 * <p>Les mocks de projection sont construits dans des variables LOCALES avant les {@code when(...)} du
 * repo : les créer à l'intérieur d'un {@code when(...).thenReturn(...)} imbriquerait deux stubbings
 * (l'argument est évalué au milieu du stubbing extérieur) → UnfinishedStubbingException.</p>
 */
class ServiceSignauxFraudeTest {

    private static final String ETAB = "ETB-ABJ-001";
    private static final String PATIENT = "PAT-42";
    private static final Instant AS_OF = Instant.parse("2026-08-09T12:00:00Z");

    private RequetesSignauxFraude requetes;
    private ServiceSignauxFraude service;

    @BeforeEach
    void setup() {
        requetes = mock(RequetesSignauxFraude.class);
        service = new ServiceSignauxFraude(requetes);
    }

    private SignauxFactureProj facture(UUID id, String patient, Instant createdAt) {
        SignauxFactureProj f = mock(SignauxFactureProj.class);
        when(f.getId()).thenReturn(id);
        when(f.getReference()).thenReturn("FCT-1");
        when(f.getEtablissementRef()).thenReturn(ETAB);
        when(f.getPatientRef()).thenReturn(patient);
        when(f.getMontantTtc()).thenReturn(30000L);
        when(f.getMontantCouvert()).thenReturn(21000L);
        when(f.getResteAPayer()).thenReturn(9000L);
        when(f.getCreatedAt()).thenReturn(createdAt);
        return f;
    }

    private ActePrincipalProj acte(String libelle, long montant) {
        ActePrincipalProj a = mock(ActePrincipalProj.class);
        when(a.getLibelle()).thenReturn(libelle);
        when(a.getMontant()).thenReturn(montant);
        return a;
    }

    @Test
    @DisplayName("Facture inexistante → 404 (FactureIntrouvableException)")
    void introuvable() {
        when(requetes.factureParNumero("ZZZ")).thenReturn(Optional.empty());
        assertThatThrownBy(() -> service.extraire("ZZZ", AS_OF))
                .isInstanceOf(FactureIntrouvableException.class);
    }

    @Test
    @DisplayName("Assemblage complet : acte principal, référentiel, signaux patient, délai et heure du règlement")
    void assemblageComplet() {
        UUID id = UUID.randomUUID();
        Instant emission = Instant.parse("2026-08-09T10:00:00Z");
        Instant reglement = Instant.parse("2026-08-09T10:20:00Z"); // 20 min après, heure 10 UTC
        SignauxFactureProj f = facture(id, PATIENT, emission);
        ActePrincipalProj a = acte("CONSULT", 30000L);

        when(requetes.factureParNumero("FCT-1")).thenReturn(Optional.of(f));
        when(requetes.actePrincipal(id)).thenReturn(Optional.of(a));
        when(requetes.moyenneReferenceActe(eq("CONSULT"), any())).thenReturn(28000L);
        when(requetes.nbActesIdentiquesJour(eq(ETAB), eq("CONSULT"), any(), any())).thenReturn(3L);
        when(requetes.nbFacturesEtablissement(eq(ETAB), any(), any())).thenReturn(12L);
        when(requetes.nbRemboursementsCarte(eq(PATIENT), any(), any())).thenReturn(1L);
        when(requetes.cumulWallet(eq(PATIENT), any(), any())).thenReturn(45000L);
        when(requetes.nbOpsWallet(eq(PATIENT), any(), any())).thenReturn(2L);
        when(requetes.confirmationReglement(id)).thenReturn(Optional.of(reglement));

        SignauxFactureReponse s = service.extraire("FCT-1", AS_OF);

        assertThat(s.reference()).isEqualTo("FCT-1");
        assertThat(s.etablissementRef()).isEqualTo(ETAB);
        assertThat(s.montantTtc()).isEqualTo(30000L);
        assertThat(s.montantCouvert()).isEqualTo(21000L);
        assertThat(s.resteAPayer()).isEqualTo(9000L);
        assertThat(s.montantActe()).isEqualTo(30000L);
        assertThat(s.montantActeReference()).isEqualTo(28000L);
        assertThat(s.nbActesIdentiquesJour()).isEqualTo(3L);
        assertThat(s.nbFacturesEtablissement30j()).isEqualTo(12L);
        assertThat(s.nbRemboursementsCarte7j()).isEqualTo(1L);
        assertThat(s.montantCumuleWallet24h()).isEqualTo(45000L);
        assertThat(s.nbOpsWallet1h()).isEqualTo(2L);
        assertThat(s.heureOperation()).isEqualTo(10);          // heure du règlement (UTC)
        assertThat(s.delaiFacturePaiementMinutes()).isEqualTo(20L);
    }

    @Test
    @DisplayName("Patient absent : signaux keyés patient = 0 et jamais interrogés")
    void patientAbsent() {
        UUID id = UUID.randomUUID();
        SignauxFactureProj f = facture(id, null, Instant.parse("2026-08-09T09:00:00Z"));
        ActePrincipalProj a = acte("CONSULT", 10000L);

        when(requetes.factureParNumero("FCT-1")).thenReturn(Optional.of(f));
        when(requetes.actePrincipal(id)).thenReturn(Optional.of(a));
        when(requetes.moyenneReferenceActe(anyString(), any())).thenReturn(10000L);
        when(requetes.nbActesIdentiquesJour(anyString(), anyString(), any(), any())).thenReturn(1L);
        when(requetes.nbFacturesEtablissement(anyString(), any(), any())).thenReturn(5L);
        when(requetes.confirmationReglement(id)).thenReturn(Optional.empty());

        SignauxFactureReponse s = service.extraire("FCT-1", AS_OF);

        assertThat(s.nbRemboursementsCarte7j()).isZero();
        assertThat(s.montantCumuleWallet24h()).isZero();
        assertThat(s.nbOpsWallet1h()).isZero();
        verify(requetes, never()).nbRemboursementsCarte(any(), any(), any());
        verify(requetes, never()).cumulWallet(any(), any(), any());
        verify(requetes, never()).nbOpsWallet(any(), any(), any());
    }

    @Test
    @DisplayName("Aucune ligne d'acte : montant acte / référentiel / répétition = 0")
    void acteAbsent() {
        UUID id = UUID.randomUUID();
        SignauxFactureProj f = facture(id, PATIENT, Instant.parse("2026-08-09T09:00:00Z"));

        when(requetes.factureParNumero("FCT-1")).thenReturn(Optional.of(f));
        when(requetes.actePrincipal(id)).thenReturn(Optional.empty());
        when(requetes.nbFacturesEtablissement(anyString(), any(), any())).thenReturn(2L);
        when(requetes.nbRemboursementsCarte(anyString(), any(), any())).thenReturn(0L);
        when(requetes.cumulWallet(anyString(), any(), any())).thenReturn(0L);
        when(requetes.nbOpsWallet(anyString(), any(), any())).thenReturn(0L);
        when(requetes.confirmationReglement(id)).thenReturn(Optional.empty());

        SignauxFactureReponse s = service.extraire("FCT-1", AS_OF);

        assertThat(s.montantActe()).isZero();
        assertThat(s.montantActeReference()).isZero();
        assertThat(s.nbActesIdentiquesJour()).isZero();
        verify(requetes, never()).moyenneReferenceActe(any(), any());
    }

    @Test
    @DisplayName("Facture non réglée : heure = émission (UTC), délai = 0")
    void nonReglee() {
        UUID id = UUID.randomUUID();
        Instant emission = Instant.parse("2026-08-09T03:30:00Z"); // heure 3 UTC
        SignauxFactureProj f = facture(id, PATIENT, emission);
        ActePrincipalProj a = acte("CONSULT", 5000L);

        when(requetes.factureParNumero("FCT-1")).thenReturn(Optional.of(f));
        when(requetes.actePrincipal(id)).thenReturn(Optional.of(a));
        when(requetes.moyenneReferenceActe(anyString(), any())).thenReturn(5000L);
        when(requetes.nbActesIdentiquesJour(anyString(), anyString(), any(), any())).thenReturn(1L);
        when(requetes.nbFacturesEtablissement(anyString(), any(), any())).thenReturn(1L);
        when(requetes.nbRemboursementsCarte(anyString(), any(), any())).thenReturn(0L);
        when(requetes.cumulWallet(anyString(), any(), any())).thenReturn(0L);
        when(requetes.nbOpsWallet(anyString(), any(), any())).thenReturn(0L);
        when(requetes.confirmationReglement(id)).thenReturn(Optional.empty());

        SignauxFactureReponse s = service.extraire("FCT-1", AS_OF);

        assertThat(s.heureOperation()).isEqualTo(3);
        assertThat(s.delaiFacturePaiementMinutes()).isZero();
    }
}
