package ci.masante.payment.service;

import ci.masante.payment.domain.model.EntreeAudit;
import ci.masante.payment.repository.EntreeAuditRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.Optional;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

/**
 * Journal d'audit append-only à hachage chaîné (CDC_06 §9.7). Dépôt simulé en mémoire (Mockito) →
 * test pur, exécuté pendant le build (sans PostgreSQL).
 */
class ServiceAuditTest {

    private static final String HASH_GENESIS =
            "0000000000000000000000000000000000000000000000000000000000000000";

    private final List<EntreeAudit> store = new ArrayList<>();
    private ServiceAudit audit;

    @BeforeEach
    void setup() {
        store.clear();
        // Ancre GENESIS (posée par Flyway en réel).
        store.add(new EntreeAudit(0, "GENESIS", "system", "genesis", "{}", "GENESIS", HASH_GENESIS));

        EntreeAuditRepository depot = mock(EntreeAuditRepository.class);
        when(depot.save(any(EntreeAudit.class))).thenAnswer(inv -> {
            EntreeAudit e = inv.getArgument(0);
            store.add(e);
            return e;
        });
        when(depot.findDerniereVerrouillee()).thenAnswer(inv ->
                store.stream().max(Comparator.comparingLong(EntreeAudit::getSequence)));
        when(depot.findAllByOrderBySequenceAsc()).thenAnswer(inv ->
                store.stream().sorted(Comparator.comparingLong(EntreeAudit::getSequence)).toList());

        audit = new ServiceAudit(depot, new ObjectMapper());
    }

    @Test
    @DisplayName("Séquence monotone et chaînage des hash")
    void chainage() {
        EntreeAudit e1 = audit.enregistrer("PaymentInitiated", "payment", "p1", Map.of("montant", 6000));
        EntreeAudit e2 = audit.enregistrer("PaymentConfirmed", "payment", "p1", Map.of("statut", "SUCCESS"));

        assertThat(e1.getSequence()).isEqualTo(1);
        assertThat(e2.getSequence()).isEqualTo(2);
        assertThat(e1.getPreviousHash()).isEqualTo(HASH_GENESIS);
        assertThat(e2.getPreviousHash()).isEqualTo(e1.getHash());
        assertThat(e1.getHash()).hasSize(64);
    }

    @Test
    @DisplayName("Une chaîne intègre est validée")
    void integriteOk() {
        audit.enregistrer("PaymentInitiated", "payment", "p1", Map.of("montant", 6000));
        audit.enregistrer("PaymentConfirmed", "payment", "p1", Map.of("statut", "SUCCESS"));

        assertThat(audit.verifierIntegrite()).isTrue();
    }

    @Test
    @DisplayName("Une entrée falsifiée (hash incohérent) est détectée")
    void integriteRompue() {
        audit.enregistrer("PaymentInitiated", "payment", "p1", Map.of("montant", 6000));
        // Injection d'une entrée au hash mensonger : la vérification doit échouer.
        store.add(new EntreeAudit(2, "PaymentConfirmed", "payment", "p1",
                "{\"statut\":\"SUCCESS\"}", store.get(store.size() - 1).getHash(), "deadbeef"));

        assertThat(audit.verifierIntegrite()).isFalse();
    }

    @Test
    @DisplayName("Sans aucun mouvement (hors GENESIS), la chaîne reste intègre")
    void seulementGenesis() {
        assertThat(Optional.of(audit.verifierIntegrite())).contains(true);
    }
}
