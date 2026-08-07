package ci.masante.payment.domain.carte;

import ci.masante.payment.domain.model.PaiementStatut;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Projection sous-état carte → PaiementStatut générique (§5.2). Test PUR. Prouve l'invariant CLÉ (interdit
 * #1) : {@code REMBOURSEE_PARTIELLE → SUCCESS} (le remboursement partiel ne bouge PAS le statut générique) →
 * la machine partagée n'est jamais forcée. Vérifie aussi la totalité (chaque valeur a une image).
 */
class MappingStatutCarteTest {

    @Test
    @DisplayName("Chaque StatutCarte a une projection (totalité, aucune exception)")
    void totalite() {
        for (StatutCarte s : StatutCarte.values()) {
            assertThat(MappingStatutCarte.versPaiement(s)).isNotNull();
        }
    }

    @Test
    @DisplayName("Projections nominales")
    void projections() {
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.CREEE)).isEqualTo(PaiementStatut.INITIATED);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.EN_ATTENTE_CLIENT)).isEqualTo(PaiementStatut.PENDING);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.AUTHENTIFIEE)).isEqualTo(PaiementStatut.PROCESSING);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.AUTORISEE)).isEqualTo(PaiementStatut.PROCESSING);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.CAPTUREE)).isEqualTo(PaiementStatut.SUCCESS);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.REMBOURSEE)).isEqualTo(PaiementStatut.REFUNDED);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.REFUSEE)).isEqualTo(PaiementStatut.FAILED);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.EXPIREE)).isEqualTo(PaiementStatut.CANCELLED);
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.ABANDONNEE)).isEqualTo(PaiementStatut.CANCELLED);
    }

    @Test
    @DisplayName("Invariant clé : REMBOURSEE_PARTIELLE reste SUCCESS (n'induit aucune transition générique)")
    void remboursementPartielResteSucces() {
        assertThat(MappingStatutCarte.versPaiement(StatutCarte.REMBOURSEE_PARTIELLE))
                .isEqualTo(PaiementStatut.SUCCESS)
                .isEqualTo(MappingStatutCarte.versPaiement(StatutCarte.CAPTUREE));
    }
}
