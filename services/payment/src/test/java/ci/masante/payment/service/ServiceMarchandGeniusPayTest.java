package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.model.IdentifiantMarchand;
import ci.masante.payment.repository.IdentifiantMarchandRepository;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.Optional;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

/**
 * B4 (ADR-056, S7) — {@code estConfigure} répond à « cet établissement peut-il encaisser en ligne
 * aujourd'hui ? », JAMAIS en lisant les secrets. Test PUR (Mockito) : aucune base, aucun contexte
 * Spring.
 */
class ServiceMarchandGeniusPayTest {

    private IdentifiantMarchandRepository marchands;
    private ServiceMarchandGeniusPay service;

    @BeforeEach
    void preparer() {
        marchands = mock(IdentifiantMarchandRepository.class);
        service = new ServiceMarchandGeniusPay(marchands, mock(GestionnaireSecretsMarchand.class),
                mock(ServiceAudit.class), new ProprietesGeniusPay());
    }

    private IdentifiantMarchand marchandSansWebhook() {
        return new IdentifiantMarchand(UUID.randomUUID(), "CI-ETS000001", AdaptateurGeniusPay.PSP,
                "slug", "pk_x", new byte[] {1}, new byte[] {2}, (short) 1, "sandbox");
    }

    @Test
    @DisplayName("Aucun compte marchand enregistré → non configuré")
    void nonConfigureSiAucunCompte() {
        when(marchands.findByEtablissementRefAndPspAndActifIsTrue("CI-ETS000001", AdaptateurGeniusPay.PSP))
                .thenReturn(Optional.empty());

        assertThat(service.estConfigure("CI-ETS000001")).isFalse();
    }

    @Test
    @DisplayName("Compte marchand SANS secret webhook → non configuré (un paiement resterait bloqué)")
    void nonConfigureSiSansSecretWebhook() {
        when(marchands.findByEtablissementRefAndPspAndActifIsTrue("CI-ETS000001", AdaptateurGeniusPay.PSP))
                .thenReturn(Optional.of(marchandSansWebhook()));

        assertThat(service.estConfigure("CI-ETS000001")).isFalse();
    }

    @Test
    @DisplayName("Compte marchand actif avec secret webhook → configuré")
    void configureSiCompletEtActif() {
        IdentifiantMarchand marchand = marchandSansWebhook();
        marchand.poserSecretWebhook(new byte[] {3}, new byte[] {4});
        when(marchands.findByEtablissementRefAndPspAndActifIsTrue("CI-ETS000001", AdaptateurGeniusPay.PSP))
                .thenReturn(Optional.of(marchand));

        assertThat(service.estConfigure("CI-ETS000001")).isTrue();
    }

    @Test
    @DisplayName("La question posée porte le PSP GeniusPay, jamais un autre prestataire")
    void interrogeLeBonPsp() {
        service.estConfigure("CI-ETS000001");

        org.mockito.Mockito.verify(marchands)
                .findByEtablissementRefAndPspAndActifIsTrue("CI-ETS000001", AdaptateurGeniusPay.PSP);
    }
}
