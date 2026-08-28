package ci.masante.payment.domain.model;

import ci.masante.payment.domain.statemachine.MachineEtatsGeniusPay;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.EnumSource;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Le sous-état GeniusPay et sa projection sur la machine partagée (ADR-044 §B3). Test PUR.
 *
 * <p>Ce fichier garde deux décisions que rien d'autre ne protège : {@code INITIEE_INCERTAINE} ne se
 * projette <b>jamais</b> sur {@code PENDING}, et {@code EXPIREE} se projette sur {@code FAILED}.
 * Toutes deux se « corrigent » en un caractère, avec les meilleures intentions du monde.</p>
 */
class StatutGeniusPayTest {

    @Test
    @DisplayName("INITIEE_INCERTAINE se projette sur INITIATED, JAMAIS sur PENDING")
    void incertaine_ne_devient_jamais_pending() {
        // PENDING affirmerait qu'une transaction attend chez le prestataire. C'est précisément ce
        // qu'on ignore : l'appel s'est perdu, elle existe peut-être, peut-être pas.
        assertThat(StatutGeniusPay.INITIEE_INCERTAINE.versStatutPartage())
                .isEqualTo(PaiementStatut.INITIATED)
                .isNotEqualTo(PaiementStatut.PENDING);
    }

    @Test
    @DisplayName("EXPIREE se projette sur FAILED — la machine partagée n'a pas d'état « expiré »")
    void expiree_devient_failed() {
        // Le détail « expiré » reste dans le sous-état pour la réconciliation et le back-office.
        // Ajouter EXPIRED à la machine partagée modifierait un contrat validé G5 côté mobile et web.
        assertThat(StatutGeniusPay.EXPIREE.versStatutPartage()).isEqualTo(PaiementStatut.FAILED);
        assertThat(PaiementStatut.values()).noneMatch(s -> s.name().equals("EXPIRED"));
    }

    @ParameterizedTest
    @EnumSource(StatutGeniusPay.class)
    @DisplayName("Chaque sous-état a une projection — aucun ne tombe dans un fourre-tout")
    void chaque_sous_etat_se_projette(StatutGeniusPay statut) {
        assertThat(statut.versStatutPartage()).isNotNull();
    }

    @Test
    @DisplayName("La table de projection est exactement celle qu'impose l'ADR-044")
    void projection_conforme_a_l_adr() {
        assertThat(StatutGeniusPay.INITIEE.versStatutPartage()).isEqualTo(PaiementStatut.INITIATED);
        assertThat(StatutGeniusPay.EN_ATTENTE.versStatutPartage()).isEqualTo(PaiementStatut.PENDING);
        assertThat(StatutGeniusPay.EN_COURS.versStatutPartage()).isEqualTo(PaiementStatut.PROCESSING);
        assertThat(StatutGeniusPay.REUSSIE.versStatutPartage()).isEqualTo(PaiementStatut.SUCCESS);
        assertThat(StatutGeniusPay.ECHOUEE.versStatutPartage()).isEqualTo(PaiementStatut.FAILED);
        assertThat(StatutGeniusPay.ANNULEE.versStatutPartage()).isEqualTo(PaiementStatut.CANCELLED);
        assertThat(StatutGeniusPay.REMBOURSEE.versStatutPartage()).isEqualTo(PaiementStatut.REFUNDED);
    }

    @Test
    @DisplayName("Un état terminal ne se remplace jamais, sauf REUSSIE → REMBOURSEE")
    void etats_terminaux() {
        assertThat(MachineEtatsGeniusPay.estAutorisee(StatutGeniusPay.REUSSIE, StatutGeniusPay.REMBOURSEE)).isTrue();
        assertThat(MachineEtatsGeniusPay.estAutorisee(StatutGeniusPay.REUSSIE, StatutGeniusPay.ECHOUEE)).isFalse();
        assertThat(MachineEtatsGeniusPay.estAutorisee(StatutGeniusPay.ECHOUEE, StatutGeniusPay.REUSSIE)).isFalse();
        assertThat(MachineEtatsGeniusPay.estAutorisee(StatutGeniusPay.ANNULEE, StatutGeniusPay.REUSSIE)).isFalse();
        assertThat(MachineEtatsGeniusPay.estAutorisee(StatutGeniusPay.EXPIREE, StatutGeniusPay.REUSSIE)).isFalse();
    }

    @Test
    @DisplayName("Un renvoi à l'identique n'est pas une transition")
    void meme_etat_refuse() {
        // C'est le cas normal du cinquième renvoi d'un webhook déjà appliqué : refusé ici, classé en
        // doublon plus haut. Le laisser passer réécrirait l'état et referait partir une notification.
        assertThat(MachineEtatsGeniusPay.estAutorisee(StatutGeniusPay.REUSSIE, StatutGeniusPay.REUSSIE)).isFalse();
    }

    @Test
    @DisplayName("L'incertitude se lève vers n'importe quelle issue, y compris un succès direct")
    void incertaine_peut_aller_partout() {
        // Le webhook peut annoncer un succès sans que nous ayons jamais observé EN_ATTENTE : nous
        // n'avons rien observé du tout, c'est le sens même de cet état.
        assertThat(MachineEtatsGeniusPay.estAutorisee(
                StatutGeniusPay.INITIEE_INCERTAINE, StatutGeniusPay.REUSSIE)).isTrue();
        assertThat(MachineEtatsGeniusPay.estAutorisee(
                StatutGeniusPay.INITIEE_INCERTAINE, StatutGeniusPay.EXPIREE)).isTrue();
    }
}
