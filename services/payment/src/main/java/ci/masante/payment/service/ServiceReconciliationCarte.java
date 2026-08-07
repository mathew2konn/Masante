package ci.masante.payment.service;

import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.PasserelleCarte;
import ci.masante.payment.domain.carte.RegistrePasserellesCarte;
import ci.masante.payment.domain.carte.StatutCarte;
import ci.masante.payment.domain.carte.StatutPasserelle;
import ci.masante.payment.domain.model.CarteReconciliation;
import ci.masante.payment.domain.model.CarteTransaction;
import ci.masante.payment.repository.CarteReconciliationRepository;
import ci.masante.payment.repository.CarteTransactionRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Réconciliation quotidienne carte ↔ PSP (CDC_06 §6.3, ADR-015). VRAIE confrontation à DEUX sources
 * INDÉPENDANTES : la source LOCALE ({@code carte_transactions}) et la source PSP (la vérité de la
 * passerelle via {@code recupererStatut}) — contrairement à l'auditeur d'intégrité INTERNE (P5.3b-4) qui
 * n'avait qu'une source. La source PSP est SIMULÉE ici (adaptateur déterministe).
 *
 * <p><b>Détection SEULE</b> : les écarts sont consignés dans {@code carte_reconciliations}, jamais corrigés
 * automatiquement (un rapprochement peut confondre une action légitime tardive et une anomalie ; la décision
 * revient à un humain). Idempotent par {@code UNIQUE(date_rapport, psp)}.</p>
 */
@Service
public class ServiceReconciliationCarte {

    /** Catégorie d'issue « macro » comparable entre les deux sources (indépendante de la granularité d'états). */
    private enum Categorie {
        REUSSIE, ECHOUEE, EXPIREE, EN_COURS
    }

    private final CarteTransactionRepository carteTransactions;
    private final CarteReconciliationRepository reconciliations;
    private final RegistrePasserellesCarte passerelles;
    private final ServiceAudit audit;
    private final ObjectMapper json;

    public ServiceReconciliationCarte(CarteTransactionRepository carteTransactions,
                                      CarteReconciliationRepository reconciliations,
                                      RegistrePasserellesCarte passerelles,
                                      ServiceAudit audit,
                                      ObjectMapper json) {
        this.carteTransactions = carteTransactions;
        this.reconciliations = reconciliations;
        this.passerelles = passerelles;
        this.audit = audit;
        this.json = json;
    }

    /** Réconcilie tous les PSP enregistrés pour une journée. */
    @Transactional
    public List<CarteReconciliation> executerJournee(LocalDate date) {
        List<CarteReconciliation> rapports = new ArrayList<>();
        for (String psp : passerelles.psps()) {
            rapports.add(executer(date, psp));
        }
        return rapports;
    }

    @Transactional
    public CarteReconciliation executer(LocalDate date, String psp) {
        Instant debut = date.atStartOfDay(ZoneOffset.UTC).toInstant();
        Instant fin = date.plusDays(1).atStartOfDay(ZoneOffset.UTC).toInstant();
        List<CarteTransaction> locales =
                carteTransactions.findByPspAndCreeLeGreaterThanEqualAndCreeLeLessThan(psp, debut, fin);
        PasserelleCarte passerelle = passerelles.pour(psp);

        int nbPsp = 0;
        long montantPsp = 0;
        long montantLocal = 0;
        List<Map<String, Object>> ecarts = new ArrayList<>();

        for (CarteTransaction tx : locales) {
            // 2e source : la vérité PSP (indépendante du registre local).
            StatutPasserelle vue = passerelle.recupererStatut(tx.getRefPasserelle());
            Categorie local = categoriserLocal(tx.getStatutCarte());
            Categorie cotePsp = categoriserPsp(vue.issue());

            if (cotePsp == Categorie.REUSSIE) {
                nbPsp++;
                montantPsp += tx.getMontant();
            }
            if (local == Categorie.REUSSIE) {
                montantLocal += tx.getMontantCapture();
            }
            if (estEcart(local, cotePsp)) {
                Map<String, Object> e = new LinkedHashMap<>();
                e.put("transactionId", tx.getId().toString());
                e.put("refPasserelle", tx.getRefPasserelle());
                e.put("statutLocal", tx.getStatutCarte().name());
                e.put("categorieLocale", local.name());
                e.put("issuePsp", vue.issue().name());
                e.put("categoriePsp", cotePsp.name());
                ecarts.add(e);
            }
        }

        CarteReconciliation rapport = reconciliations.findByDateRapportAndPsp(date, psp)
                .orElseGet(() -> new CarteReconciliation(date, psp));
        rapport.renseigner(nbPsp, locales.size(), montantPsp, montantLocal, ecarts.size(), enJson(ecarts));
        reconciliations.save(rapport);

        audit.enregistrer("CardReconciliationRun", "card_reconciliation", date + "/" + psp, Map.of(
                "nbLocales", locales.size(), "nbPsp", nbPsp, "nbEcarts", ecarts.size()));
        return rapport;
    }

    /**
     * Un écart n'est signalé que pour une DIVERGENCE FINANCIÈRE réelle : un côté a « bougé l'argent »
     * (REUSSIE) et l'autre non (ECHOUEE/EXPIREE). Les cas « en vol » (EN_COURS d'un côté) sont tolérés — la
     * réconciliation est un instantané, pas un finaliseur ; de même EXPIREE(local) vs EN_ATTENTE(PSP) = un
     * abandon légitimement expiré côté local, pas une anomalie.
     */
    private static boolean estEcart(Categorie local, Categorie psp) {
        if (local == psp || local == Categorie.EN_COURS || psp == Categorie.EN_COURS) {
            return false;
        }
        return local == Categorie.REUSSIE || psp == Categorie.REUSSIE;
    }

    /** Projection de l'état LOCAL (fin de cycle) vers une catégorie macro. */
    private static Categorie categoriserLocal(StatutCarte statut) {
        return switch (statut) {
            case CAPTUREE, REMBOURSEE_PARTIELLE, REMBOURSEE, AUTORISEE -> Categorie.REUSSIE;
            case REFUSEE -> Categorie.ECHOUEE;
            case EXPIREE, ABANDONNEE -> Categorie.EXPIREE;
            case CREEE, EN_ATTENTE_CLIENT, AUTHENTIFIEE -> Categorie.EN_COURS;
        };
    }

    /** Projection de l'issue PSP vers la même catégorie macro. */
    private static Categorie categoriserPsp(IssuePasserelle issue) {
        return switch (issue) {
            case AUTORISE, CAPTURE -> Categorie.REUSSIE;
            case REFUSE -> Categorie.ECHOUEE;
            case EXPIRE -> Categorie.EXPIREE;
            case EN_ATTENTE -> Categorie.EN_COURS;
        };
    }

    private String enJson(List<Map<String, Object>> ecarts) {
        try {
            return json.writeValueAsString(ecarts);
        } catch (com.fasterxml.jackson.core.JsonProcessingException e) {
            throw new IllegalStateException("Sérialisation des écarts de réconciliation impossible", e);
        }
    }
}
