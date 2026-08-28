package ci.masante.payment.domain.model;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.test.util.ReflectionTestUtils;

import java.util.Collection;
import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Lot 6 — l'accroche du canal interne vit sur l'AGRÉGAT, pas dans les services.
 *
 * <p>Ce qui est prouvé ici est le point de conception, pas un détail : les deux services qui font
 * aujourd'hui aboutir un paiement — {@code ServicePaiement} (mobile money) et {@code ServiceCarte}
 * (projection du sous-état carte) — n'ont AUCUN code d'accrochage. Leur seul geste commun est
 * {@code setStatut} + {@code save}. Le test rejoue les deux séquences réelles, sur l'agrégat nu,
 * et exige une émission dans chaque cas : une accroche écrite dans l'un des deux services ferait
 * échouer la moitié de ce fichier, et un troisième chemin écrit demain resterait couvert.</p>
 */
class PaiementAccrocheTerminaleTest {

    private Paiement paiementPersiste() {
        Paiement p = new Paiement("idem-" + UUID.randomUUID(), "CORR-1", 15000, "XOF",
                "MOBILE_MONEY", ObjetPaiement.RENDEZ_VOUS, "******78", "ETS-1", "PAT-1");
        // En production l'identifiant est posé au premier `save()`, bien avant toute transition.
        ReflectionTestUtils.setField(p, "id", UUID.randomUUID());
        return p;
    }

    @SuppressWarnings("unchecked")
    private Collection<Object> evenementsDe(Paiement p) {
        return (Collection<Object>) ReflectionTestUtils.invokeMethod(p, "evenements");
    }

    @Test
    @DisplayName("Chemin ServicePaiement : la séquence complète n'émet qu'au terminal")
    void cheminMobileMoney() {
        Paiement p = paiementPersiste();

        p.setStatut(PaiementStatut.PENDING);
        p.setStatut(PaiementStatut.PROCESSING);
        assertThat(evenementsDe(p))
                .as("Aucun état intermédiaire n'apprend quoi que ce soit à un partenaire")
                .isEmpty();

        p.setStatut(PaiementStatut.SUCCESS);

        assertThat(evenementsDe(p)).hasSize(1);
        TransitionTerminaleEvenement e = (TransitionTerminaleEvenement) evenementsDe(p).iterator().next();
        assertThat(e.statut()).isEqualTo(PaiementStatut.SUCCESS);
        assertThat(e.correlationId()).isEqualTo("CORR-1");
        assertThat(e.montant()).isEqualTo(15000);
        assertThat(e.paiementId()).isNotNull();
    }

    @Test
    @DisplayName("Chemin ServiceCarte : la projection du sous-état émet par le même passage obligé")
    void cheminCarte() {
        Paiement p = paiementPersiste();
        // ServiceCarte ne pose un statut générique QUE si la projection change (capture → SUCCESS).
        p.setStatut(PaiementStatut.PENDING);
        p.setStatut(PaiementStatut.PROCESSING);
        p.setStatut(PaiementStatut.SUCCESS);

        assertThat(evenementsDe(p)).hasSize(1);
    }

    @Test
    @DisplayName("Un remboursement est une issue, donc une notification")
    void remboursement() {
        Paiement p = paiementPersiste();
        p.setStatut(PaiementStatut.SUCCESS);
        ReflectionTestUtils.invokeMethod(p, "viderEvenements");

        p.setStatut(PaiementStatut.REFUNDED);

        assertThat(evenementsDe(p)).hasSize(1);
        assertThat(((TransitionTerminaleEvenement) evenementsDe(p).iterator().next()).statut())
                .isEqualTo(PaiementStatut.REFUNDED);
    }

    @Test
    @DisplayName("Repasser dans le même état n'est pas un fait nouveau : rien n'est annoncé")
    void memeEtatNAnnonceRien() {
        Paiement p = paiementPersiste();
        p.setStatut(PaiementStatut.SUCCESS);
        ReflectionTestUtils.invokeMethod(p, "viderEvenements");

        p.setStatut(PaiementStatut.SUCCESS);

        assertThat(evenementsDe(p)).isEmpty();
    }

    @Test
    @DisplayName("Une seconde sauvegarde de la même entité ne republie pas le même fait")
    void pasDeDoublonApresPublication() {
        Paiement p = paiementPersiste();
        p.setStatut(PaiementStatut.SUCCESS);
        assertThat(evenementsDe(p)).hasSize(1);

        // Spring Data appelle ceci juste après la publication ; le `paiements.save()` suivant
        // (ServiceCarte en fait un pour poser `confirmedAt`) ne doit plus rien émettre.
        ReflectionTestUtils.invokeMethod(p, "viderEvenements");

        assertThat(evenementsDe(p)).isEmpty();
    }

    @Test
    @DisplayName("Les états terminaux sont exactement ceux de la machine partagée — pas d'EXPIRED")
    void etatsTerminaux() {
        assertThat(PaiementStatut.SUCCESS.estTerminal()).isTrue();
        assertThat(PaiementStatut.FAILED.estTerminal()).isTrue();
        assertThat(PaiementStatut.CANCELLED.estTerminal()).isTrue();
        assertThat(PaiementStatut.REFUNDED.estTerminal()).isTrue();
        assertThat(PaiementStatut.INITIATED.estTerminal()).isFalse();
        assertThat(PaiementStatut.PENDING.estTerminal()).isFalse();
        assertThat(PaiementStatut.PROCESSING.estTerminal()).isFalse();
    }
}
