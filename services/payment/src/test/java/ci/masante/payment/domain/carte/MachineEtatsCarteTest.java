package ci.masante.payment.domain.carte;

import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.statemachine.MachineEtatsPaiement;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.EnumSource;

import java.util.Map;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/**
 * Machine à états carte (P5.4a) — pure. Prouve : transitions légales/illégales, terminaux, mapping TOTAL,
 * et l'invariant croisé « toute transition carte induit une transition légale (ou un no-op) dans la
 * machine générique » — donc la machine partagée n'est jamais forcée à une transition interdite.
 */
class MachineEtatsCarteTest {

    @Test
    @DisplayName("Chaque transition autorisée produit l'état attendu")
    void transitionsLegales() {
        assertThat(MachineEtatsCarte.transition(StatutCarte.CREEE, EvenementCarte.INTERACTION_CLIENT_REQUISE))
                .isEqualTo(StatutCarte.EN_ATTENTE_CLIENT);
        assertThat(MachineEtatsCarte.transition(StatutCarte.CREEE, EvenementCarte.AUTHENTIFICATION_REUSSIE))
                .isEqualTo(StatutCarte.AUTHENTIFIEE);
        assertThat(MachineEtatsCarte.transition(StatutCarte.EN_ATTENTE_CLIENT, EvenementCarte.EXPIRATION))
                .isEqualTo(StatutCarte.EXPIREE);
        assertThat(MachineEtatsCarte.transition(StatutCarte.AUTHENTIFIEE, EvenementCarte.AUTORISATION_OK))
                .isEqualTo(StatutCarte.AUTORISEE);
        assertThat(MachineEtatsCarte.transition(StatutCarte.AUTORISEE, EvenementCarte.CAPTURE_OK))
                .isEqualTo(StatutCarte.CAPTUREE);
        assertThat(MachineEtatsCarte.transition(StatutCarte.CAPTUREE, EvenementCarte.REMBOURSEMENT_PARTIEL))
                .isEqualTo(StatutCarte.REMBOURSEE_PARTIELLE);
        assertThat(MachineEtatsCarte.transition(StatutCarte.REMBOURSEE_PARTIELLE, EvenementCarte.REMBOURSEMENT_PARTIEL))
                .isEqualTo(StatutCarte.REMBOURSEE_PARTIELLE);
        assertThat(MachineEtatsCarte.transition(StatutCarte.REMBOURSEE_PARTIELLE, EvenementCarte.REMBOURSEMENT_TOTAL))
                .isEqualTo(StatutCarte.REMBOURSEE);
    }

    @Test
    @DisplayName("Une transition non listée lève TransitionCarteIllegale")
    void transitionsIllegales() {
        assertThatThrownBy(() -> MachineEtatsCarte.transition(StatutCarte.CREEE, EvenementCarte.CAPTURE_OK))
                .isInstanceOf(TransitionCarteIllegale.class);
        assertThatThrownBy(() -> MachineEtatsCarte.transition(StatutCarte.CAPTUREE, EvenementCarte.AUTORISATION_OK))
                .isInstanceOf(TransitionCarteIllegale.class);
        assertThatThrownBy(() -> MachineEtatsCarte.transition(StatutCarte.AUTORISEE, EvenementCarte.REFUS))
                .isInstanceOf(TransitionCarteIllegale.class);
    }

    @ParameterizedTest
    @EnumSource(StatutCarte.class)
    @DisplayName("Les terminaux n'ont aucune sortie ; les non-terminaux en ont au moins une")
    void terminaux(StatutCarte statut) {
        boolean terminal = switch (statut) {
            case REFUSEE, EXPIREE, ABANDONNEE, REMBOURSEE -> true;
            default -> false;
        };
        assertThat(MachineEtatsCarte.estTerminal(statut)).isEqualTo(terminal);
        assertThat(MachineEtatsCarte.transitionsDepuis(statut).isEmpty()).isEqualTo(terminal);
    }

    @ParameterizedTest
    @EnumSource(StatutCarte.class)
    @DisplayName("Le mapping vers PaiementStatut est TOTAL : chaque StatutCarte a une image non nulle")
    void mappingTotal(StatutCarte statut) {
        assertThat(MappingStatutCarte.versPaiement(statut)).isNotNull();
    }

    @ParameterizedTest
    @EnumSource(StatutCarte.class)
    @DisplayName("Invariant croisé : chaque transition carte induit une transition générique légale (ou un no-op)")
    void chaqueTransitionCarteEstLegaleAuGenerique(StatutCarte depart) {
        PaiementStatut gDepart = MappingStatutCarte.versPaiement(depart);
        for (Map.Entry<EvenementCarte, StatutCarte> e : MachineEtatsCarte.transitionsDepuis(depart).entrySet()) {
            PaiementStatut gArrivee = MappingStatutCarte.versPaiement(e.getValue());
            boolean noOp = gDepart == gArrivee; // même statut générique → aucun mouvement à valider
            boolean legaleGenerique = MachineEtatsPaiement.estAutorisee(gDepart, gArrivee);
            assertThat(noOp || legaleGenerique)
                    .as("carte %s -%s-> %s  ⇒  générique %s -> %s",
                            depart, e.getKey(), e.getValue(), gDepart, gArrivee)
                    .isTrue();
        }
    }
}
