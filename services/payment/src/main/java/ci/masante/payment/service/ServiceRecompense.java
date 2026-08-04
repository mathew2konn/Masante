package ci.masante.payment.service;

import ci.masante.payment.domain.model.CampagneCashback;
import ci.masante.payment.domain.model.TypeOperationWallet;
import ci.masante.payment.domain.model.WalletOperation;
import ci.masante.payment.domain.reward.CampagneInvalideException;
import ci.masante.payment.domain.reward.ReglesCashback;
import ci.masante.payment.domain.wallet.OperationWalletInvalideException;
import ci.masante.payment.repository.CampagneCashbackRepository;
import ci.masante.payment.repository.WalletOperationRepository;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Cashback (campagnes) + Bonus (CDC_06 §6.1/§6.2) — <b>frontière</b> : calcul et décision backend seul,
 * règles = données (campagnes). Le CRÉDIT du cashback est <b>gaté</b> ({@code credit-enabled}, OFF par
 * défaut) tant que le chemin de remboursement (§11) qui appelle le clawback n'existe pas ; en dry-run,
 * le montant est calculé et renvoyé sans créditer. Le bonus (non lié à une op source) est actif.
 */
@Service
public class ServiceRecompense {

    private final CampagneCashbackRepository campagnes;
    private final WalletOperationRepository operations;
    private final ServiceWallet wallet;
    private final ServiceAudit audit;
    private final boolean creditCashbackActif;

    public ServiceRecompense(CampagneCashbackRepository campagnes, WalletOperationRepository operations,
                             ServiceWallet wallet, ServiceAudit audit,
                             @Value("${masante.payment.wallet.cashback.credit-enabled:false}") boolean creditCashbackActif) {
        this.campagnes = campagnes;
        this.operations = operations;
        this.wallet = wallet;
        this.audit = audit;
        this.creditCashbackActif = creditCashbackActif;
    }

    // --- campagnes ----------------------------------------------------------------------------

    @Transactional
    public CampagneCashback creerCampagne(CampagneCashback campagne, String acteur) {
        exigerActeur(acteur);
        CampagneCashback c = campagnes.save(campagne);
        audit.enregistrer("CashbackCampagneCreee", "campagne", c.getId().toString(),
                Map.of("code", c.getCode(), "type", c.getTypeOperationSource(),
                        "tauxBps", c.getTauxBps(), "acteur", acteur));
        return c;
    }

    @Transactional(readOnly = true)
    public List<CampagneCashback> listerCampagnes() {
        return campagnes.findAllByOrderByCreatedAtDesc();
    }

    @Transactional
    public CampagneCashback desactiverCampagne(UUID id, String acteur) {
        exigerActeur(acteur);
        CampagneCashback c = campagnes.findById(id)
                .orElseThrow(() -> new CampagneInvalideException("Campagne introuvable."));
        c.desactiver();
        campagnes.save(c);
        audit.enregistrer("CashbackCampagneDesactivee", "campagne", c.getId().toString(),
                Map.of("code", c.getCode(), "acteur", acteur));
        return c;
    }

    // --- bonus (actif) ------------------------------------------------------------------------

    @Transactional
    public WalletOperation accorderBonus(UUID walletId, long montant, String motif, String acteur, String cle) {
        exigerActeur(acteur);
        if (montant <= 0) {
            throw new OperationWalletInvalideException("Le montant du bonus doit être positif.");
        }
        if (motif == null || motif.isBlank()) {
            throw new OperationWalletInvalideException("Le motif du bonus est obligatoire.");
        }
        WalletOperation op = wallet.crediterBonus(walletId, montant, motif, cle);
        audit.enregistrer("BonusAccorde", "wallet", walletId.toString(),
                Map.of("montant", montant, "motif", motif, "acteur", acteur));
        return op;
    }

    // --- cashback (gaté) ----------------------------------------------------------------------

    /**
     * Évalue (et crédite si le flag est actif) le cashback d'une opération source. Idempotent via la
     * clé dérivée {@code cashback:{sourceId}}. Ne lève pas d'erreur si aucune campagne/aucun montant :
     * renvoie {@code accorde=false} + une raison (pas de boucle de retry côté client).
     */
    @Transactional
    public ResultatCashback evaluerCashback(UUID walletId, UUID operationSourceId) {
        // déjà accordé ? (rejeu / double appel) → ne pas recompter le budget
        List<WalletOperation> dejaAccorde = operations.cashbacksDeSource(operationSourceId);
        if (!dejaAccorde.isEmpty()) {
            WalletOperation c = dejaAccorde.get(0);
            return new ResultatCashback(true, c.getMontant(), c.getCampagneCode(), "déjà accordé");
        }

        WalletOperation source = operations.findById(operationSourceId)
                .orElseThrow(() -> new OperationWalletInvalideException("Opération source introuvable."));
        if (source.getSourceWalletId() == null || !source.getSourceWalletId().equals(walletId)) {
            throw new OperationWalletInvalideException("L'opération source n'appartient pas à ce portefeuille.");
        }

        CampagneCashback campagne = campagnes
                .findByTypeOperationSourceAndActifTrue(source.getType().name())
                .orElse(null);
        if (campagne == null || !campagne.estValide(Instant.now())) {
            return new ResultatCashback(false, 0, null, "aucune campagne éligible");
        }
        // Sérialise les contrôles de budget/plafonds concurrents (verrou pessimiste).
        if (campagne.exigeSerialisation()) {
            campagne = campagnes.findByIdVerrouille(campagne.getId()).orElseThrow();
        }

        long base = source.getMontant();
        long montant = ReglesCashback.calculer(base, campagne.getTauxBps(), campagne.getPlafondParOperation());
        if (montant <= 0) {
            return new ResultatCashback(false, 0, campagne.getCode(), "montant nul");
        }

        String plafondAtteint = controlerPlafonds(campagne, walletId, montant, source.getCreatedAt());
        if (plafondAtteint != null) {
            return new ResultatCashback(false, montant, campagne.getCode(), plafondAtteint);
        }

        if (!creditCashbackActif) {
            // Dry-run : montant CALCULÉ, non crédité (prêt à activer §11). Jamais une erreur.
            return new ResultatCashback(false, montant, campagne.getCode(),
                    "crédit désactivé (prêt à activer §11)");
        }

        wallet.crediterCashback(walletId, montant, campagne.getCode(), operationSourceId,
                "cashback:" + operationSourceId);
        audit.enregistrer("CashbackAccorde", "wallet", walletId.toString(),
                Map.of("montant", montant, "campagne", campagne.getCode(),
                        "operationSource", operationSourceId.toString()));
        return new ResultatCashback(true, montant, campagne.getCode(), "accordé");
    }

    /** @return la portée du plafond atteint, ou null si tout passe. */
    private String controlerPlafonds(CampagneCashback c, UUID walletId, long montant, Instant dateSource) {
        if (c.getBudgetTotal() != null
                && operations.sommeCashbackCampagne(c.getCode()) + montant > c.getBudgetTotal()) {
            return "budget de campagne épuisé";
        }
        if (c.getPlafondParWallet() > 0
                && operations.sommeCashbackCampagneWallet(c.getCode(), walletId) + montant > c.getPlafondParWallet()) {
            return "plafond par portefeuille atteint";
        }
        if (c.getPlafondParWalletParJour() > 0) {
            Instant debutJour = LocalDate.ofInstant(dateSource, ZoneOffset.UTC)
                    .atStartOfDay(ZoneOffset.UTC).toInstant();
            Instant finJour = debutJour.plusSeconds(86_400);
            long dejaJour = operations.sommeCashbackCampagneWalletJour(c.getCode(), walletId, debutJour, finJour);
            if (dejaJour + montant > c.getPlafondParWalletParJour()) {
                return "plafond journalier par portefeuille atteint";
            }
        }
        return null;
    }

    // --- clawback (interne + admin) -----------------------------------------------------------

    /**
     * Reprend (clawback) tout ou partie du cashback accordé sur une opération source, quand celle-ci
     * est remboursée. Idempotence <b>par remboursement</b> ({@code cashback-annul:{remboursementId}}) :
     * un second remboursement partiel n'est PAS confondu avec un rejeu. Proportionnel, plafonné au
     * cashback d'origine ; le remboursement qui solde l'op source reprend le reliquat exact.
     */
    @Transactional
    public ResultatCashback annulerCashback(UUID operationSourceId, UUID remboursementId,
                                            long montantRembourse, long montantSource,
                                            boolean soldeLOpSource, String acteur) {
        exigerActeur(acteur);
        List<WalletOperation> cashbacks = operations.cashbacksDeSource(operationSourceId);
        if (cashbacks.isEmpty()) {
            return new ResultatCashback(false, 0, null, "aucun cashback à reprendre");
        }
        WalletOperation cashback = cashbacks.get(0);
        long dejaClawe = operations.sommeClawbackSource(operationSourceId);
        long montant = ReglesCashback.calculerClawback(cashback.getMontant(), dejaClawe,
                montantRembourse, montantSource, soldeLOpSource);
        if (montant <= 0) {
            return new ResultatCashback(false, 0, cashback.getCampagneCode(), "rien à reprendre");
        }
        wallet.reprendreCashback(cashback.getDestWalletId(), montant, cashback.getCampagneCode(),
                operationSourceId, "cashback-annul:" + remboursementId);
        audit.enregistrer("CashbackReprisAdmin", "wallet", cashback.getDestWalletId().toString(),
                Map.of("montant", montant, "operationSource", operationSourceId.toString(),
                        "remboursement", remboursementId.toString(), "acteur", acteur));
        return new ResultatCashback(true, montant, cashback.getCampagneCode(), "repris");
    }

    private static void exigerActeur(String acteur) {
        if (acteur == null || acteur.isBlank()) {
            throw new ActeurRequisException();
        }
    }

    /** Résultat d'une évaluation/opération de cashback. {@code montant} = calculé (peut ne pas être crédité). */
    public record ResultatCashback(boolean accorde, long montant, String campagneCode, String raison) {
    }
}
