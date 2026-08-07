package ci.masante.payment.service;

import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.ModaliteCarte;
import ci.masante.payment.domain.carte.PasserelleCarte;
import ci.masante.payment.domain.carte.RegistrePasserellesCarte;
import ci.masante.payment.domain.carte.StatutCarte;
import ci.masante.payment.domain.carte.StatutPasserelle;
import ci.masante.payment.domain.model.CarteReconciliation;
import ci.masante.payment.domain.model.CarteTransaction;
import ci.masante.payment.repository.CarteReconciliationRepository;
import ci.masante.payment.repository.CarteTransactionRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.test.util.ReflectionTestUtils;

import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.Set;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

/**
 * Réconciliation carte ↔ PSP (§6.3). Prouve la leçon P5.3b-4 : « un run vert sur données saines ne prouve
 * rien » → on injecte une anomalie et on vérifie qu'elle est détectée. Confrontation à 2 sources : registre
 * local (mocké) vs vérité PSP (adaptateur mocké). Test PUR (Mockito) → exécuté pendant le build.
 */
class ServiceReconciliationCarteTest {

    private static final String PSP = "sim";
    private static final LocalDate JOUR = LocalDate.of(2026, 8, 6);

    private CarteTransactionRepository carteTransactions;
    private CarteReconciliationRepository reconciliations;
    private PasserelleCarte passerelle;
    private ServiceReconciliationCarte service;

    @BeforeEach
    void setup() {
        carteTransactions = mock(CarteTransactionRepository.class);
        reconciliations = mock(CarteReconciliationRepository.class);
        RegistrePasserellesCarte registre = mock(RegistrePasserellesCarte.class);
        passerelle = mock(PasserelleCarte.class);
        ServiceAudit audit = mock(ServiceAudit.class);

        when(registre.psps()).thenReturn(Set.of(PSP));
        when(registre.pour(PSP)).thenReturn(passerelle);
        when(reconciliations.findByDateRapportAndPsp(any(), anyString())).thenReturn(Optional.empty());
        when(reconciliations.save(any(CarteReconciliation.class))).thenAnswer(inv -> inv.getArgument(0));

        service = new ServiceReconciliationCarte(carteTransactions, reconciliations, registre, audit,
                new ObjectMapper());
    }

    private CarteTransaction transaction(String ref, StatutCarte statut, long capture) {
        CarteTransaction tx = new CarteTransaction(UUID.randomUUID(), PSP, ModaliteCarte.TOKENISEE, ref,
                StatutCarte.CREEE, 6000, "XOF");
        ReflectionTestUtils.setField(tx, "id", UUID.randomUUID());
        tx.setStatutCarte(statut);
        tx.setMontantCapture(capture);
        return tx;
    }

    private void avecTransactions(CarteTransaction... txs) {
        when(carteTransactions.findByPspAndCreeLeGreaterThanEqualAndCreeLeLessThan(eq(PSP), any(), any()))
                .thenReturn(List.of(txs));
    }

    private StatutPasserelle vuePsp(IssuePasserelle issue) {
        return new StatutPasserelle(issue, null, null, null, null, null, null);
    }

    @Test
    @DisplayName("Données saines : capturée localement ⇄ autorisée côté PSP → 0 écart")
    void sain() {
        avecTransactions(transaction("REF1", StatutCarte.CAPTUREE, 6000));
        when(passerelle.recupererStatut("REF1")).thenReturn(vuePsp(IssuePasserelle.AUTORISE));

        CarteReconciliation rapport = service.executer(JOUR, PSP);

        assertThat(rapport.getNbEcarts()).isZero();
        assertThat(rapport.getNbTransactionsLocales()).isEqualTo(1);
        assertThat(rapport.getMontantLocal()).isEqualTo(6000);
    }

    @Test
    @DisplayName("Anomalie injectée : capturée localement mais REFUSÉE côté PSP → 1 écart détecté")
    void anomalieDetectee() {
        avecTransactions(transaction("REF1", StatutCarte.CAPTUREE, 6000));
        when(passerelle.recupererStatut("REF1")).thenReturn(vuePsp(IssuePasserelle.REFUSE));

        CarteReconciliation rapport = service.executer(JOUR, PSP);

        assertThat(rapport.getNbEcarts()).isEqualTo(1);
        assertThat(rapport.getEcarts()).contains("REF1").contains("CAPTUREE").contains("REFUSE");
    }

    @Test
    @DisplayName("Abandon expiré localement vs EN_ATTENTE côté PSP : toléré (pas d'écart de timing)")
    void abandonTolere() {
        avecTransactions(transaction("REF2", StatutCarte.EXPIREE, 0));
        when(passerelle.recupererStatut("REF2")).thenReturn(vuePsp(IssuePasserelle.EN_ATTENTE));

        CarteReconciliation rapport = service.executer(JOUR, PSP);

        assertThat(rapport.getNbEcarts()).isZero();
    }

    @Test
    @DisplayName("Idempotence : réexécuter une journée recalcule la même ligne (date, psp)")
    void idempotent() {
        CarteReconciliation existant = new CarteReconciliation(JOUR, PSP);
        when(reconciliations.findByDateRapportAndPsp(JOUR, PSP)).thenReturn(Optional.of(existant));
        avecTransactions(transaction("REF1", StatutCarte.CAPTUREE, 6000));
        when(passerelle.recupererStatut("REF1")).thenReturn(vuePsp(IssuePasserelle.AUTORISE));

        CarteReconciliation rapport = service.executer(JOUR, PSP);

        assertThat(rapport).isSameAs(existant); // réutilise la ligne, ne crée pas de doublon
        assertThat(rapport.getNbEcarts()).isZero();
    }
}
