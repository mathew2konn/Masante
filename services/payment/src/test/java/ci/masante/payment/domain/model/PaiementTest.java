package ci.masante.payment.domain.model;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * B4 (ADR-056) — {@code setStatut} reste le point d'accroche unique du canal interne (lot 6), mais
 * l'événement qu'il publie porte désormais {@code etablissementRef}/{@code factureId} recopiés de
 * l'agrégat, et {@code fraisPasserelle} passé par l'appelant qui les connaît (S1/S2/S3).
 *
 * <p>Tests PURS : aucun repository, aucune base — l'agrégat seul.</p>
 */
class PaiementTest {

    private Paiement nouveauPaiement(String etablissementRef) {
        return new Paiement("idem-" + UUID.randomUUID(), "CORR-1", 15000, "XOF", "geniuspay",
                ObjetPaiement.FACTURE, null, etablissementRef, "PAT-1");
    }

    private List<TransitionTerminaleEvenement> evenementsDe(Paiement p) {
        return p.evenements().stream().map(TransitionTerminaleEvenement.class::cast).toList();
    }

    @Test
    @DisplayName("setStatut(statut) délègue avec des frais NULS — forme des canaux sans frais")
    void setStatutSansFraisDelegueAvecFraisNuls() {
        Paiement p = nouveauPaiement("CI-ETS000001");
        p.setStatut(PaiementStatut.SUCCESS);

        List<TransitionTerminaleEvenement> evenements = evenementsDe(p);
        assertThat(evenements).hasSize(1);
        assertThat(evenements.get(0).fraisPasserelle()).isNull();
    }

    @Test
    @DisplayName("setStatut(statut, frais) porte les frais fournis par l'appelant")
    void setStatutAvecFraisLesPorte() {
        Paiement p = nouveauPaiement("CI-ETS000001");
        p.setStatut(PaiementStatut.SUCCESS, 250L);

        assertThat(evenementsDe(p).get(0).fraisPasserelle()).isEqualTo(250L);
    }

    @Test
    @DisplayName("L'événement recopie etablissementRef DE L'AGRÉGAT, jamais un paramètre")
    void evenementRecopieEtablissementRefDeLAgregat() {
        Paiement p = nouveauPaiement("CI-ETS000042");
        p.setStatut(PaiementStatut.SUCCESS, 100L);

        assertThat(evenementsDe(p).get(0).etablissementRef()).isEqualTo("CI-ETS000042");
    }

    @Test
    @DisplayName("etablissementRef absent à la construction (canaux simulés) → événement NUL, jamais deviné")
    void evenementEtablissementRefNulSiAbsent() {
        Paiement p = nouveauPaiement(null);
        p.setStatut(PaiementStatut.FAILED);

        assertThat(evenementsDe(p).get(0).etablissementRef()).isNull();
    }

    @Test
    @DisplayName("factureId posé APRÈS construction (règlement) est recopié à la transition suivante")
    void evenementRecopieFactureIdPoseApresConstruction() {
        Paiement p = nouveauPaiement("CI-ETS000001");
        UUID facture = UUID.randomUUID();
        p.setFactureId(facture);
        p.setStatut(PaiementStatut.SUCCESS, 100L);

        assertThat(evenementsDe(p).get(0).factureId()).isEqualTo(facture);
    }

    @Test
    @DisplayName("factureId jamais posé (paiement hors facture, ou terminal non-SUCCESS) → événement NUL")
    void evenementFactureIdNulSiJamaisPose() {
        Paiement p = nouveauPaiement("CI-ETS000001");
        p.setStatut(PaiementStatut.FAILED, 0L);

        assertThat(evenementsDe(p).get(0).factureId()).isNull();
    }

    @Test
    @DisplayName("Repasser dans le même état n'émet aucun second événement (garde de répétition intacte)")
    void repasserDansLeMemeEtatNEmetRien() {
        Paiement p = nouveauPaiement("CI-ETS000001");
        p.setStatut(PaiementStatut.SUCCESS, 100L);
        p.setStatut(PaiementStatut.SUCCESS, 999L);

        assertThat(evenementsDe(p)).hasSize(1);
        assertThat(evenementsDe(p).get(0).fraisPasserelle()).isEqualTo(100L);
    }

    @Test
    @DisplayName("L'événement porte le CANAL de l'agrégat — carte/mobile money portent aussi etablissementRef")
    void evenementPorteLeCanalDeLAgregat() {
        Paiement p = new Paiement("idem-1", "CORR-1", 15000, "XOF", "geniuspay",
                ObjetPaiement.FACTURE, null, "CI-ETS000001", "PAT-1");
        p.setStatut(PaiementStatut.SUCCESS, 100L);

        assertThat(evenementsDe(p).get(0).canal()).isEqualTo("geniuspay");
    }
}
