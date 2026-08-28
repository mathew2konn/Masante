package ci.masante.payment.service;

import ci.masante.payment.config.ProprietesGeniusPay;
import ci.masante.payment.domain.gateway.PasserellePaiement;
import ci.masante.payment.domain.gateway.RegistrePasserelles;
import ci.masante.payment.domain.gateway.RequetePaiement;
import ci.masante.payment.domain.gateway.ResultatPaiement;
import ci.masante.payment.domain.gateway.geniuspay.AdaptateurGeniusPay;
import ci.masante.payment.domain.gateway.geniuspay.GeniusPayInjoignableException;
import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.StatutGeniusPay;
import ci.masante.payment.repository.GeniusPayTransactionRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.test.util.ReflectionTestUtils;

import java.util.List;
import java.util.Optional;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.times;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

/**
 * Initiation d'un checkout (§7.5) : les deux refus, la réutilisation, et le non-rejeu.
 *
 * <p>Le cas qu'il faut lire est {@code panne_reseau_laisse_incertain_sans_second_appel} : la
 * passerelle est appelée <b>une seule fois</b> et la transaction reste en incertitude. C'est la règle
 * la plus importante du lot, et celle qui se casse le plus facilement.</p>
 */
class ServiceGeniusPayTest {

    private static final long PLANCHER = 5000;

    private PaiementRepository paiements;
    private GeniusPayTransactionRepository transactions;
    private PasserellePaiement passerelle;
    private ServiceIdempotence idempotence;
    private ServiceGeniusPay service;

    @BeforeEach
    void preparer() {
        paiements = mock(PaiementRepository.class);
        transactions = mock(GeniusPayTransactionRepository.class);
        passerelle = mock(PasserellePaiement.class);
        idempotence = mock(ServiceIdempotence.class);

        when(idempotence.acquerir(anyString())).thenReturn(true);
        when(paiements.findByIdempotencyKey(anyString())).thenReturn(Optional.empty());
        when(paiements.save(any())).thenAnswer(i -> {
            Paiement p = i.getArgument(0);
            if (p.getId() == null) {
                ReflectionTestUtils.setField(p, "id", UUID.randomUUID());
            }
            return p;
        });
        when(transactions.save(any())).thenAnswer(i -> {
            GeniusPayTransaction t = i.getArgument(0);
            if (t.getId() == null) {
                ReflectionTestUtils.setField(t, "id", UUID.randomUUID());
            }
            return t;
        });
        when(transactions.findByFactureId(any())).thenReturn(List.of());
        when(passerelle.supporte(AdaptateurGeniusPay.CANAL)).thenReturn(true);

        RegistrePasserelles registre = new RegistrePasserelles(List.of(passerelle));
        ProprietesGeniusPay proprietes = new ProprietesGeniusPay();
        proprietes.setBaseUrl("http://inutilise");

        service = new ServiceGeniusPay(paiements, mock(TransitionPaiementRepository.class), transactions,
                registre, idempotence, mock(ServiceAudit.class), proprietes, PLANCHER, null);
        // Auto-référence par proxy : hors contexte Spring, on referme la boucle sur l'instance.
        ReflectionTestUtils.setField(service, "self", service);
    }

    private ServiceGeniusPay.DemandeCheckout demande(long montant) {
        return new ServiceGeniusPay.DemandeCheckout(UUID.randomUUID(), montant, "XOF",
                "ETS-042", "PAT-1", "CORR-1", ObjetPaiement.FACTURE);
    }

    @Test
    @DisplayName("Sous le plancher métier, refus explicite — et rien n'est écrit")
    void refuse_sous_le_plancher_metier() {
        // Ce n'est pas une erreur : le paiement sur place reste la voie normale, et le message le dit.
        assertThatThrownBy(() -> service.initierPourFacture(demande(4999), "idem-1"))
                .isInstanceOf(PaiementEnLigneIndisponibleException.class)
                .hasMessageContaining("5000");

        // Un montant refusé ne consomme pas de clé d'idempotence et ne crée aucune ligne.
        verify(paiements, never()).save(any());
        verify(idempotence, never()).acquerir(anyString());
    }

    @Test
    @DisplayName("Sous le minimum du prestataire, refus distinct — le message dit laquelle des deux règles joue")
    void refuse_sous_le_minimum_geniuspay() {
        ServiceGeniusPay avecPlancherBas = new ServiceGeniusPay(paiements,
                mock(TransitionPaiementRepository.class), transactions,
                new RegistrePasserelles(List.of(passerelle)), idempotence, mock(ServiceAudit.class),
                proprietesAvecMinimum(200), 100, null);
        ReflectionTestUtils.setField(avecPlancherBas, "self", avecPlancherBas);

        assertThatThrownBy(() -> avecPlancherBas.initierPourFacture(demande(150), "idem-2"))
                .isInstanceOf(PaiementEnLigneIndisponibleException.class)
                .hasMessageContaining("prestataire")
                .hasMessageContaining("200");
    }

    private static ProprietesGeniusPay proprietesAvecMinimum(long minimum) {
        ProprietesGeniusPay p = new ProprietesGeniusPay();
        p.setBaseUrl("http://inutilise");
        p.setMontantMinimum(minimum);
        return p;
    }

    @Test
    @DisplayName("Une panne réseau laisse la transaction INCERTAINE — et n'appelle PAS une seconde fois")
    void panne_reseau_laisse_incertain_sans_second_appel() {
        // Rejouer, c'est risquer deux transactions chez le prestataire pour une facture, donc deux
        // débits sur un patient. Le compteur d'appels est la garantie ; le statut en est la trace.
        when(passerelle.payer(any())).thenThrow(
                new GeniusPayInjoignableException("delai depasse", new RuntimeException()));

        var resultat = service.initierPourFacture(demande(15000), "idem-3");

        verify(passerelle, times(1)).payer(any());
        assertThat(resultat.transaction().getStatutGeniusPay())
                .isEqualTo(StatutGeniusPay.INITIEE_INCERTAINE);
        // L'incertitude est DITE à l'appelant, jamais présentée comme un échec ni comme un succès.
        assertThat(resultat.avertissements()).isNotEmpty();
    }

    @Test
    @DisplayName("Un checkout déjà ouvert pour la facture est réutilisé, jamais doublé")
    void initiation_unique_par_facture() {
        // Deux liens payables pour la même facture, et le patient n'a aucun moyen de savoir lequel
        // est le bon — ni nous de savoir lequel il paiera.
        UUID factureId = UUID.randomUUID();
        UUID paiementId = UUID.randomUUID();
        GeniusPayTransaction existante = new GeniusPayTransaction(paiementId, "MS-ETS042-DEJA", factureId);
        existante.setStatutGeniusPay(StatutGeniusPay.EN_ATTENTE);
        existante.setCheckoutUrl("https://geniuspay.ci/checkout/DEJA");
        when(transactions.findByFactureId(factureId)).thenReturn(List.of(existante));

        Paiement paiementExistant = new Paiement("idem-ancien", "CORR", 15000, "XOF",
                AdaptateurGeniusPay.CANAL, ObjetPaiement.FACTURE, null, "ETS-042", "PAT-1");
        ReflectionTestUtils.setField(paiementExistant, "id", paiementId);
        when(paiements.findById(paiementId)).thenReturn(Optional.of(paiementExistant));

        var resultat = service.initierPourFacture(new ServiceGeniusPay.DemandeCheckout(
                factureId, 15000, "XOF", "ETS-042", "PAT-1", "CORR-2", ObjetPaiement.FACTURE), "idem-4");

        assertThat(resultat.rejoue()).isTrue();
        assertThat(resultat.transaction().getReferenceInterne()).isEqualTo("MS-ETS042-DEJA");
        assertThat(resultat.transaction().getCheckoutUrl()).isEqualTo("https://geniuspay.ci/checkout/DEJA");
        // Aucune requête sortante, aucune nouvelle ligne.
        verify(passerelle, never()).payer(any());
    }

    @Test
    @DisplayName("Un succès enregistre référence, lien, échéance et frais réels")
    void succes_enregistre_ce_que_le_prestataire_a_dit() {
        java.time.Instant echeance = java.time.Instant.parse("2026-08-28T00:42:37Z");
        when(passerelle.payer(any())).thenReturn(new ResultatPaiement(PaiementStatut.PENDING,
                "SANDBOX_ABC123", "ouvert",
                new ResultatPaiement.DetailCheckout("https://geniuspay.ci/checkout/SANDBOX_ABC123",
                        echeance, 250L, 14750L, "orange_money")));

        var resultat = service.initierPourFacture(demande(15000), "idem-5");
        GeniusPayTransaction t = resultat.transaction();

        assertThat(t.getStatutGeniusPay()).isEqualTo(StatutGeniusPay.EN_ATTENTE);
        assertThat(t.getReferencePasserelle()).isEqualTo("SANDBOX_ABC123");
        assertThat(t.getExpireLe()).isEqualTo(echeance);
        // Les frais viennent du prestataire et ne se recalculent pas.
        assertThat(t.getFraisPasserelle()).isEqualTo(250L);
        assertThat(t.getMontantNet()).isEqualTo(14750L);
        assertThat(t.getCanal()).isEqualTo("orange_money");
    }

    @Test
    @DisplayName("La référence interne porte l'établissement et reste unique à chaque appel")
    void reference_interne_unique_et_lisible() {
        String a = ServiceGeniusPay.referenceInterne("ETS-042");
        String b = ServiceGeniusPay.referenceInterne("ETS-042");

        assertThat(a).startsWith("MS-ETS042-").hasSize("MS-ETS042-".length() + 26);
        assertThat(a).isNotEqualTo(b);
        // Un établissement absent ne fabrique pas une référence bancale : il se dit.
        assertThat(ServiceGeniusPay.referenceInterne(null)).startsWith("MS-NA-");
    }

    @Test
    @DisplayName("Le montant est transmis tel quel, en francs entiers")
    void montant_transmis_en_entier() {
        when(passerelle.payer(any())).thenReturn(new ResultatPaiement(PaiementStatut.PENDING,
                "SANDBOX_X", "ouvert", null));

        service.initierPourFacture(demande(15000), "idem-6");

        org.mockito.ArgumentCaptor<RequetePaiement> capture =
                org.mockito.ArgumentCaptor.forClass(RequetePaiement.class);
        verify(passerelle).payer(capture.capture());
        assertThat(capture.getValue().montant()).isEqualTo(15000L);
        assertThat(capture.getValue().etablissementRef()).isEqualTo("ETS-042");
        // Le téléphone n'est jamais transmis à la passerelle : il ne quitte pas le service.
        assertThat(capture.getValue().telephone()).isNull();
    }
}
