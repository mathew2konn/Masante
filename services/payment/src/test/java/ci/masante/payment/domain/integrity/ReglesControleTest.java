package ci.masante.payment.domain.integrity;

import ci.masante.payment.domain.model.FactureStatut;
import ci.masante.payment.domain.model.OwnerTypeWallet;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import java.util.UUID;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * Règles PURES du contrôle d'intégrité (P5.3b-4) — frontière. Prouve que chaque contrôle DÉTECTE son
 * anomalie ET reste vert sur du sain. Test pur, exécuté au build (G3).
 */
class ReglesControleTest {

    private final ReglesControle regles = new ReglesControle();
    private final UUID ref = UUID.randomUUID();

    // ── Contrôle 1 — double écriture ──────────────────────────────────────────────────────────

    @Test
    @DisplayName("Opération saine : 2 écritures, somme 0 → aucun écart")
    void operationSaine() {
        assertThat(regles.operationDesequilibree(ref, 2, 0)).isEmpty();
    }

    @Test
    @DisplayName("Opération à somme non nulle → écart OPERATION_DESEQUILIBREE")
    void operationSommeNonNulle() {
        assertThat(regles.operationDesequilibree(ref, 2, 100))
                .get().extracting(Ecart::type).isEqualTo(TypeEcart.OPERATION_DESEQUILIBREE);
    }

    @Test
    @DisplayName("Opération avec un nombre d'écritures ≠ 2 → écart (ligne orpheline)")
    void operationNombreEcrituresInvalide() {
        assertThat(regles.operationDesequilibree(ref, 1, 1000)).isPresent();
        assertThat(regles.operationDesequilibree(ref, 3, 0)).isPresent();
    }

    @Test
    @DisplayName("Grand livre : Σ = 0 → OK ; Σ ≠ 0 → écart GRAND_LIVRE_NON_NUL")
    void grandLivre() {
        assertThat(regles.grandLivreNonNul(0)).isEmpty();
        assertThat(regles.grandLivreNonNul(-5))
                .get().extracting(Ecart::type).isEqualTo(TypeEcart.GRAND_LIVRE_NON_NUL);
    }

    @Test
    @DisplayName("Solde négatif : autorisé pour SYSTEME, écart pour PATIENT/ETABLISSEMENT")
    void soldeNegatif() {
        assertThat(regles.soldeNegatif(ref, OwnerTypeWallet.SYSTEME, -9000)).isEmpty();
        assertThat(regles.soldeNegatif(ref, OwnerTypeWallet.PATIENT, -1)).isPresent();
        assertThat(regles.soldeNegatif(ref, OwnerTypeWallet.ETABLISSEMENT, -1)).isPresent();
        assertThat(regles.soldeNegatif(ref, OwnerTypeWallet.PATIENT, 0)).isEmpty();
    }

    // ── Contrôle 2 — facture ↔ règlement ──────────────────────────────────────────────────────

    @Test
    @DisplayName("Facture cohérente selon son statut → aucun écart")
    void factureCoherente() {
        assertThat(regles.factureIncoherente("F1", FactureStatut.EMISE, 0, 5000)).isEmpty();
        assertThat(regles.factureIncoherente("F2", FactureStatut.PARTIELLEMENT_PAYEE, 2000, 5000)).isEmpty();
        assertThat(regles.factureIncoherente("F3", FactureStatut.PAYEE, 5000, 5000)).isEmpty();
        // Facture couverte (reste 0) : PAYEE même avec montant réglé nul.
        assertThat(regles.factureIncoherente("F4", FactureStatut.PAYEE, 0, 0)).isEmpty();
    }

    @Test
    @DisplayName("PAYEE mais montant réglé < reste à payer → écart FACTURE_STATUT_INCOHERENT")
    void payeeSousReglee() {
        assertThat(regles.factureIncoherente("F5", FactureStatut.PAYEE, 3000, 10000))
                .get().extracting(Ecart::type).isEqualTo(TypeEcart.FACTURE_STATUT_INCOHERENT);
    }

    @Test
    @DisplayName("EMISE avec un montant déjà réglé → écart (devrait être partielle/payée)")
    void emiseAvecReglement() {
        assertThat(regles.factureIncoherente("F6", FactureStatut.EMISE, 1000, 5000)).isPresent();
    }

    @Test
    @DisplayName("Encaissement passerelle ≤ montant réglé → OK ; > → écart ENCAISSEMENT_NON_REPERCUTE")
    void encaissement() {
        // Le wallet gonfle légitimement montantRegle sans somme passerelle → pas d'écart.
        assertThat(regles.encaissementNonRepercute("F7", 5000, 3000)).isEmpty();
        assertThat(regles.encaissementNonRepercute("F8", 0, 5000))
                .get().extracting(Ecart::type).isEqualTo(TypeEcart.ENCAISSEMENT_NON_REPERCUTE);
    }

    // ── Contrôle 3 — cashback ─────────────────────────────────────────────────────────────────

    @Test
    @DisplayName("Cashback ≤ budget → OK ; budget null (illimité) → OK ; > budget → écart")
    void cashbackBudget() {
        assertThat(regles.cashbackBudgetDepasse("C1", 800, 1000L)).isEmpty();
        assertThat(regles.cashbackBudgetDepasse("C2", 999999, null)).isEmpty();
        assertThat(regles.cashbackBudgetDepasse("C3", 2000, 1000L))
                .get().extracting(Ecart::type).isEqualTo(TypeEcart.CASHBACK_BUDGET_DEPASSE);
    }

    @Test
    @DisplayName("Clawback ≤ origine → OK ; clawback > origine → écart CLAWBACK_SUPERIEUR_ORIGINE")
    void clawback() {
        assertThat(regles.clawbackSuperieurOrigine(ref, 500, 500)).isEmpty();
        assertThat(regles.clawbackSuperieurOrigine(ref, 500, 300)).isEmpty();
        assertThat(regles.clawbackSuperieurOrigine(ref, 500, 800))
                .get().extracting(Ecart::type).isEqualTo(TypeEcart.CLAWBACK_SUPERIEUR_ORIGINE);
    }

    @Test
    @DisplayName("Un écart porte la bonne sévérité et un montant attendu/constaté")
    void formeEcart() {
        Ecart e = regles.clawbackSuperieurOrigine(ref, 500, 800).orElseThrow();
        assertThat(e.severite()).isEqualTo(Severite.CRITIQUE);
        assertThat(e.montantAttendu()).isEqualTo(500);
        assertThat(e.montantConstate()).isEqualTo(800);
        assertThat(e.controle()).isEqualTo(TypeControle.CASHBACK);
    }
}
