package ci.masante.payment.service;

import ci.masante.payment.domain.carte.DemandeCarte;
import ci.masante.payment.domain.carte.EvenementWebhook;
import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.ModaliteCarte;
import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.carte.PasserelleCarte;
import ci.masante.payment.domain.carte.RegistrePasserellesCarte;
import ci.masante.payment.domain.carte.ResultatCapture;
import ci.masante.payment.domain.carte.ResultatInitiation;
import ci.masante.payment.domain.carte.StatutCarte;
import ci.masante.payment.domain.carte.StatutPasserelle;
import ci.masante.payment.domain.gateway.ResultatRemboursement;
import ci.masante.payment.domain.model.CarteTransaction;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.repository.CarteEvenementWebhookRepository;
import ci.masante.payment.repository.CarteRemboursementRepository;
import ci.masante.payment.repository.CarteRepository;
import ci.masante.payment.repository.CarteTransactionRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.test.util.ReflectionTestUtils;

import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.List;
import java.util.Map;
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
 * Logique de décision du cycle carte (§5-§7). Test PUR (Mockito) → exécuté pendant le build (sans base).
 * Les dépôts sont simulés ; l'ID normalement posé par JPA est injecté à la sauvegarde (ReflectionTestUtils).
 * La concurrence RÉELLE (verrou pessimiste, dédup UNIQUE) est prouvée en G2 live — elle ne peut l'être sans
 * PostgreSQL. Ici on prouve les BRANCHES : happy path, idempotence, refus, anti-fuite webhook, expiration.
 */
class ServiceCarteTest {

    private static final String PSP = "sim";

    private PaiementRepository paiements;
    private CarteTransactionRepository carteTransactions;
    private CarteRemboursementRepository remboursements;
    private RegistrePasserellesCarte passerelles;
    private PasserelleCarte passerelle;
    private ServiceIdempotence idempotence;
    private AntiRejeuWebhook antiRejeu;
    private ServiceCarte self;
    private ServiceCarte service;

    @BeforeEach
    void setup() {
        paiements = mock(PaiementRepository.class);
        carteTransactions = mock(CarteTransactionRepository.class);
        CarteRepository cartes = mock(CarteRepository.class);
        remboursements = mock(CarteRemboursementRepository.class);
        CarteEvenementWebhookRepository webhooks = mock(CarteEvenementWebhookRepository.class);
        TransitionPaiementRepository transitions = mock(TransitionPaiementRepository.class);
        passerelles = mock(RegistrePasserellesCarte.class);
        passerelle = mock(PasserelleCarte.class);
        idempotence = mock(ServiceIdempotence.class);
        antiRejeu = mock(AntiRejeuWebhook.class);
        ServiceAudit audit = mock(ServiceAudit.class);
        ServiceFacturation facturation = mock(ServiceFacturation.class);
        self = mock(ServiceCarte.class);

        // Sauvegardes : injectent un id (comme JPA) puis renvoient l'entité.
        when(paiements.save(any(Paiement.class))).thenAnswer(inv -> avecId(inv.getArgument(0)));
        when(carteTransactions.save(any(CarteTransaction.class))).thenAnswer(inv -> avecId(inv.getArgument(0)));
        when(paiements.findByIdempotencyKey(anyString())).thenReturn(Optional.empty());
        when(passerelles.pour(PSP)).thenReturn(passerelle);
        when(passerelle.psp()).thenReturn(PSP);
        when(passerelle.modalite()).thenReturn(ModaliteCarte.TOKENISEE);

        service = new ServiceCarte(paiements, transitions, carteTransactions, cartes, remboursements,
                webhooks, passerelles, idempotence, antiRejeu, audit, facturation, new ObjectMapper(), self);
    }

    private static <T> T avecId(T entite) {
        if (ReflectionTestUtils.getField(entite, "id") == null) {
            ReflectionTestUtils.setField(entite, "id", UUID.randomUUID());
        }
        return entite;
    }

    private CommandeCarte commande(String reference) {
        return new CommandeCarte(PSP, reference, Montant.de(6000, "XOF"), "user-1",
                ObjetPaiement.RENDEZ_VOUS, null, false, "corr-1", "etab-1", "pat-1", null);
    }

    private CarteTransaction transaction(UUID paiementId, StatutCarte statut, long capture, long rembourse) {
        CarteTransaction tx = new CarteTransaction(paiementId, PSP, ModaliteCarte.TOKENISEE, "REF1",
                StatutCarte.CREEE, 6000, "XOF");
        ReflectionTestUtils.setField(tx, "id", UUID.randomUUID());
        tx.setStatutCarte(statut);
        tx.setMontantCapture(capture);
        tx.setMontantRembourse(rembourse);
        return tx;
    }

    private Paiement paiement(PaiementStatut statut) {
        Paiement p = new Paiement("key", "corr", 6000, "XOF", "carte", ObjetPaiement.RENDEZ_VOUS,
                null, "etab", "pat");
        ReflectionTestUtils.setField(p, "id", UUID.randomUUID());
        p.setStatut(statut);
        return p;
    }

    // --- initiation -----------------------------------------------------------------------------

    @Test
    @DisplayName("Frictionless : autorisation + capture synchrones → SUCCESS, aucune action client")
    void frictionlessCapture() {
        when(passerelle.initier(any(DemandeCarte.class))).thenReturn(
                new ResultatInitiation.Frictionless("REF1", "NTID", "VISA", "4242", 12, 2030));
        when(passerelle.capturer(eq("REF1"), any(Montant.class))).thenReturn(ResultatCapture.ok());

        ResultatCarte r = service.executerInitiation(commande("tok_test_frictionless"), "key");

        assertThat(r.statut()).isEqualTo(PaiementStatut.SUCCESS);
        assertThat(r.action()).isEqualTo(ActionClient.AUCUNE);
        assertThat(r.rejoue()).isFalse();
    }

    @Test
    @DisplayName("Rejeu idempotent : une clé déjà connue renvoie l'état existant sans réappeler la passerelle")
    void initierRejeu() {
        Paiement existant = paiement(PaiementStatut.PENDING);
        when(paiements.findByIdempotencyKey("key")).thenReturn(Optional.of(existant));
        when(carteTransactions.findByPaiementId(existant.getId()))
                .thenReturn(Optional.of(transaction(existant.getId(), StatutCarte.EN_ATTENTE_CLIENT, 0, 0)));

        ResultatCarte r = service.initier(commande("tok_test_challenge"), "key");

        assertThat(r.rejoue()).isTrue();
        assertThat(r.statut()).isEqualTo(PaiementStatut.PENDING);
        verify(idempotence, never()).acquerir(anyString()); // court-circuit avant le verrou
        verify(passerelle, never()).initier(any());
    }

    // --- finalisation ---------------------------------------------------------------------------

    @Test
    @DisplayName("Finalisation d'une transaction déjà terminale : idempotent, ne réinterroge pas le PSP")
    void finaliserTerminalNoOp() {
        Paiement p = paiement(PaiementStatut.SUCCESS);
        CarteTransaction tx = transaction(p.getId(), StatutCarte.CAPTUREE, 6000, 0);
        when(carteTransactions.findByPaiementId(p.getId())).thenReturn(Optional.of(tx));
        when(carteTransactions.verrouiller(tx.getId())).thenReturn(Optional.of(tx));
        when(paiements.findById(p.getId())).thenReturn(Optional.of(p));

        ResultatCarte r = service.finaliser(p.getId());

        assertThat(r.statut()).isEqualTo(PaiementStatut.SUCCESS);
        verify(passerelle, never()).recupererStatut(anyString());
    }

    @Test
    @DisplayName("Finalisation vérité serveur : PSP AUTORISE → capture → SUCCESS")
    void finaliserAutorise() {
        Paiement p = paiement(PaiementStatut.PENDING);
        CarteTransaction tx = transaction(p.getId(), StatutCarte.EN_ATTENTE_CLIENT, 0, 0);
        when(carteTransactions.findByPaiementId(p.getId())).thenReturn(Optional.of(tx));
        when(carteTransactions.verrouiller(tx.getId())).thenReturn(Optional.of(tx));
        when(paiements.findById(p.getId())).thenReturn(Optional.of(p));
        when(passerelle.recupererStatut("REF1"))
                .thenReturn(new StatutPasserelle(IssuePasserelle.AUTORISE, "NTID", "VISA", "4242", 12, 2030, null));
        when(passerelle.capturer(eq("REF1"), any(Montant.class))).thenReturn(ResultatCapture.ok());

        ResultatCarte r = service.finaliser(p.getId());

        assertThat(r.statut()).isEqualTo(PaiementStatut.SUCCESS);
        assertThat(tx.getStatutCarte()).isEqualTo(StatutCarte.CAPTUREE);
    }

    // --- remboursement --------------------------------------------------------------------------

    @Test
    @DisplayName("Remboursement au-delà du capturé → 422 (frontière : contrôle backend), passerelle non appelée")
    void remboursementExcessif() {
        Paiement p = paiement(PaiementStatut.SUCCESS);
        CarteTransaction tx = transaction(p.getId(), StatutCarte.CAPTUREE, 6000, 0);
        when(carteTransactions.findByPaiementId(p.getId())).thenReturn(Optional.of(tx));
        when(carteTransactions.verrouiller(tx.getId())).thenReturn(Optional.of(tx));
        when(paiements.findById(p.getId())).thenReturn(Optional.of(p));

        assertThatThrownBy(() -> service.executerRemboursement(p.getId(), 7000, "XOF", "motif"))
                .isInstanceOf(OperationCarteInvalideException.class);
        verify(passerelle, never()).rembourser(anyString(), any(), anyString());
    }

    @Test
    @DisplayName("Remboursement partiel : reste SUCCESS, montant remboursé cumulé mis à jour")
    void remboursementPartiel() {
        Paiement p = paiement(PaiementStatut.SUCCESS);
        CarteTransaction tx = transaction(p.getId(), StatutCarte.CAPTUREE, 6000, 0);
        when(carteTransactions.findByPaiementId(p.getId())).thenReturn(Optional.of(tx));
        when(carteTransactions.verrouiller(tx.getId())).thenReturn(Optional.of(tx));
        when(paiements.findById(p.getId())).thenReturn(Optional.of(p));
        when(remboursements.save(any())).thenAnswer(inv -> inv.getArgument(0));
        when(passerelle.rembourser(eq("REF1"), any(Montant.class), anyString()))
                .thenReturn(new ResultatRemboursement(true, "SIMRF-1", "ok"));

        ResultatCarte r = service.executerRemboursement(p.getId(), 2000, "XOF", "motif");

        assertThat(r.statut()).isEqualTo(PaiementStatut.SUCCESS);
        assertThat(tx.getStatutCarte()).isEqualTo(StatutCarte.REMBOURSEE_PARTIELLE);
        assertThat(tx.getMontantRembourse()).isEqualTo(2000);
    }

    // --- webhook (anti-fuite) -------------------------------------------------------------------

    @Test
    @DisplayName("Webhook signature invalide → 401 générique, pas de désérialisation")
    void webhookSignatureInvalide() {
        when(passerelle.verifierSignature(any(), any())).thenReturn(false);

        assertThatThrownBy(() -> service.appliquerWebhook(PSP, "{}".getBytes(), Map.of()))
                .isInstanceOf(WebhookInvalideException.class);
        verify(passerelle, never()).parserEvenement(any());
    }

    @Test
    @DisplayName("Webhook horodatage périmé (> 5 min) → 401 générique")
    void webhookPerime() {
        when(passerelle.verifierSignature(any(), any())).thenReturn(true);
        when(passerelle.parserEvenement(any())).thenReturn(evenement(Instant.now().minus(10, ChronoUnit.MINUTES)));

        assertThatThrownBy(() -> service.appliquerWebhook(PSP, "{}".getBytes(), Map.of()))
                .isInstanceOf(WebhookInvalideException.class);
    }

    @Test
    @DisplayName("Webhook déjà vu (nonce) : rejeu idempotent, aucune application")
    void webhookRejeu() {
        when(passerelle.verifierSignature(any(), any())).thenReturn(true);
        when(passerelle.parserEvenement(any())).thenReturn(evenement(Instant.now()));
        when(antiRejeu.dejaVu(eq(PSP), anyString())).thenReturn(true);

        service.appliquerWebhook(PSP, "{}".getBytes(), Map.of());

        verify(self, never()).appliquerEvenement(anyString(), any());
    }

    private EvenementWebhook evenement(Instant horodatage) {
        return new EvenementWebhook("evt-1", "payment.updated", "REF1", IssuePasserelle.AUTORISE,
                horodatage, "NTID", "VISA", "4242", 12, 2030, null);
    }

    // --- expiration -----------------------------------------------------------------------------

    @Test
    @DisplayName("Job d'expiration : un défi échu passe EN_ATTENTE_CLIENT → EXPIREE")
    void expirationDefi() {
        Paiement p = paiement(PaiementStatut.PENDING);
        CarteTransaction tx = transaction(p.getId(), StatutCarte.EN_ATTENTE_CLIENT, 0, 0);
        tx.setChallengeExpireLe(Instant.now().minus(1, ChronoUnit.MINUTES));
        when(carteTransactions.findByStatutCarteAndChallengeExpireLeBefore(eq(StatutCarte.EN_ATTENTE_CLIENT), any()))
                .thenReturn(List.of(tx));
        when(carteTransactions.verrouiller(tx.getId())).thenReturn(Optional.of(tx));
        when(paiements.findById(p.getId())).thenReturn(Optional.of(p));

        int n = service.expirerDefisEchus();

        assertThat(n).isEqualTo(1);
        assertThat(tx.getStatutCarte()).isEqualTo(StatutCarte.EXPIREE);
    }
}
