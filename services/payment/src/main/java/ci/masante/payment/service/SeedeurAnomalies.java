package ci.masante.payment.service;

import ci.masante.payment.domain.model.CampagneCashback;
import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.domain.model.FactureStatut;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.domain.model.OwnerTypeWallet;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.TypeOperationWallet;
import ci.masante.payment.domain.model.Wallet;
import ci.masante.payment.domain.model.WalletEntry;
import ci.masante.payment.domain.model.WalletOperation;
import ci.masante.payment.repository.CampagneCashbackRepository;
import ci.masante.payment.repository.FactureRepository;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.WalletEntryRepository;
import ci.masante.payment.repository.WalletOperationRepository;
import ci.masante.payment.repository.WalletRepository;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

/**
 * Injecteur d'anomalies — outil de DÉV UNIQUEMENT (gaté OFF par défaut, §config).
 *
 * <p>Un run vert sur des données saines ne prouve RIEN (il peut être vert parce que vide). Ce seedeur
 * insère volontairement des données CORROMPUES — en contournant les services (écriture directe en base)
 * — pour prouver que chaque contrôle DÉTECTE réellement son anomalie. Chaque fixture isole un type
 * d'écart (sauf A1, dont le déséquilibre casse aussi, à juste titre, l'invariant global).</p>
 *
 * <p>Ce n'est PAS une correction ni une donnée de production : à n'activer qu'en dév/démo. Les données
 * injectées sont préfixées {@code ANOMALIE-} et suffixées d'un jeton par run (ré-exécutable).</p>
 */
@Service
public class SeedeurAnomalies {

    private final boolean actif;
    private final WalletRepository wallets;
    private final WalletOperationRepository operations;
    private final WalletEntryRepository entries;
    private final FactureRepository factures;
    private final PaiementRepository paiements;
    private final CampagneCashbackRepository campagnes;

    public SeedeurAnomalies(@Value("${masante.payment.integrite.dev-seed-enabled:false}") boolean actif,
                            WalletRepository wallets, WalletOperationRepository operations,
                            WalletEntryRepository entries, FactureRepository factures,
                            PaiementRepository paiements, CampagneCashbackRepository campagnes) {
        this.actif = actif;
        this.wallets = wallets;
        this.operations = operations;
        this.entries = entries;
        this.factures = factures;
        this.paiements = paiements;
        this.campagnes = campagnes;
    }

    public boolean estActif() {
        return actif;
    }

    /** Injecte les 6 anomalies et renvoie la liste des références créées. */
    @Transactional
    public List<String> injecter() {
        String jeton = UUID.randomUUID().toString().substring(0, 8);
        int exercice = LocalDate.now(ZoneOffset.UTC).getYear();
        List<String> refs = new ArrayList<>();

        Wallet sys = wallets.save(new Wallet("ANOMALIE-SYS-" + jeton, OwnerTypeWallet.SYSTEME, "XOF"));
        Wallet sysCb = wallets.save(new Wallet("ANOMALIE-SYSCB-" + jeton, OwnerTypeWallet.SYSTEME, "XOF"));

        // A1 — opération déséquilibrée (+1000 / −900 : somme = +100). Casse aussi le grand livre global.
        Wallet a1 = wallets.save(new Wallet("ANOMALIE-A1P-" + jeton, OwnerTypeWallet.PATIENT, "XOF"));
        WalletOperation opA1 = operations.save(op(jeton, "A1", TypeOperationWallet.CREDIT, 1000));
        paire(opA1.getId(), a1.getId(), 1000, sys.getId(), -900);
        refs.add("A1 operation:" + opA1.getId() + " → OPERATION_DESEQUILIBREE (+ GRAND_LIVRE_NON_NUL)");

        // A2 — débit sans provision : opération équilibrée mais laisse un PATIENT à solde négatif.
        Wallet a2p = wallets.save(new Wallet("ANOMALIE-A2P-" + jeton, OwnerTypeWallet.PATIENT, "XOF"));
        Wallet a2e = wallets.save(new Wallet("ANOMALIE-A2E-" + jeton, OwnerTypeWallet.ETABLISSEMENT, "XOF"));
        WalletOperation opA2 = operations.save(op(jeton, "A2", TypeOperationWallet.TRANSFERT, 500));
        paire(opA2.getId(), a2p.getId(), -500, a2e.getId(), 500);
        refs.add("A2 wallet:" + a2p.getId() + " → SOLDE_NEGATIF_NON_AUTORISE");

        // A3 — facture PAYEE mais montant réglé (3000) < reste à payer (10000).
        Facture f3 = new Facture("ANOMALIE-A3-" + jeton, "ANOMALIE-ETAB", "ANOMALIE-PAT", exercice, "XOF",
                10000, 0, 0, 10000, null, null, 0, 10000, FactureStatut.PAYEE, "anomalie");
        f3.setMontantRegle(3000);
        factures.save(f3);
        refs.add("A3 facture:" + f3.getNumero() + " → FACTURE_STATUT_INCOHERENT");

        // A4 — encaissement passerelle SUCCESS (5000) non répercuté sur la facture (réglé = 0).
        Facture f4 = new Facture("ANOMALIE-A4-" + jeton, "ANOMALIE-ETAB", "ANOMALIE-PAT", exercice, "XOF",
                5000, 0, 0, 5000, null, null, 0, 5000, FactureStatut.EMISE, "anomalie");
        factures.save(f4);
        Paiement p4 = new Paiement("ANO-A4-" + UUID.randomUUID(), null, 5000, "XOF", "orange_money",
                ObjetPaiement.FACTURE, null, "ANOMALIE-ETAB", "ANOMALIE-PAT");
        p4.setStatut(PaiementStatut.SUCCESS);
        p4.setFactureId(f4.getId());
        paiements.save(p4);
        refs.add("A4 facture:" + f4.getNumero() + " → ENCAISSEMENT_NON_REPERCUTE");

        // A5 — cashback consommé (2000) au-delà du budget de campagne (1000).
        String codeA5 = "ANOMALIE-CB-BUDGET-" + jeton;
        campagnes.save(new CampagneCashback(codeA5, "Anomalie budget", "ANOMALIE_SEED", 500,
                0, 0, 0, 1000L, Instant.now().minus(1, ChronoUnit.DAYS),
                Instant.now().plus(365, ChronoUnit.DAYS), "ANOMALIE-SEED"));
        Wallet a5 = wallets.save(new Wallet("ANOMALIE-A5P-" + jeton, OwnerTypeWallet.PATIENT, "XOF"));
        WalletOperation opA5 = opCashback(jeton, "A5", TypeOperationWallet.CASHBACK, 2000, codeA5, UUID.randomUUID());
        opA5 = operations.save(opA5);
        paire(opA5.getId(), a5.getId(), 2000, sysCb.getId(), -2000);
        refs.add("A5 campagne:" + codeA5 + " → CASHBACK_BUDGET_DEPASSE");

        // A6 — clawback (800) > cashback d'origine (500) pour une même op source.
        //   Provision préalable (+1000) pour que le PATIENT ne finisse pas négatif (isole l'écart).
        UUID source = UUID.randomUUID();
        String codeA6 = "ANOMALIE-CB-CLAWBACK-" + jeton; // pas de campagne → hors contrôle budget
        Wallet a6 = wallets.save(new Wallet("ANOMALIE-A6P-" + jeton, OwnerTypeWallet.PATIENT, "XOF"));
        WalletOperation prov = operations.save(op(jeton, "A6prov", TypeOperationWallet.CREDIT, 1000));
        paire(prov.getId(), a6.getId(), 1000, sys.getId(), -1000);
        WalletOperation opCash = operations.save(
                opCashback(jeton, "A6cash", TypeOperationWallet.CASHBACK, 500, codeA6, source));
        paire(opCash.getId(), a6.getId(), 500, sysCb.getId(), -500);
        WalletOperation opClaw = operations.save(
                opCashback(jeton, "A6claw", TypeOperationWallet.CASHBACK_ANNULATION, 800, codeA6, source));
        paire(opClaw.getId(), a6.getId(), -800, sysCb.getId(), 800);
        refs.add("A6 source:" + source + " → CLAWBACK_SUPERIEUR_ORIGINE");

        return refs;
    }

    private WalletOperation op(String jeton, String tag, TypeOperationWallet type, long montant) {
        return new WalletOperation("ANO-" + tag + "-" + jeton + "-" + UUID.randomUUID(), type, montant,
                null, null, "ANOMALIE-" + tag, "anomalie " + tag, null);
    }

    private WalletOperation opCashback(String jeton, String tag, TypeOperationWallet type, long montant,
                                       String campagneCode, UUID sourceId) {
        WalletOperation op = op(jeton, tag, type, montant);
        op.rattacher(campagneCode, sourceId); // avant persist : colonnes non modifiables ensuite
        return op;
    }

    private void paire(UUID operationId, UUID walletA, long montantA, UUID walletB, long montantB) {
        entries.save(new WalletEntry(operationId, walletA, montantA));
        entries.save(new WalletEntry(operationId, walletB, montantB));
    }
}
