package ci.masante.payment.service;

import ci.masante.payment.domain.model.CommissionConfig;
import ci.masante.payment.repository.CommissionConfigRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.Optional;

/**
 * Gestion des taux de commission plateforme (CDC_06 §11). Taux = DONNÉE (jamais codée). Historisé,
 * append-only, non-chevauchant : ouvrir un nouveau taux CLÔTURE le précédent dans la même transaction
 * (aucun recouvrement possible) ; l'index unique partiel {@code uq_cfg_un_seul_taux_ouvert} est le
 * filet sous concurrence. Aucun taux d'amorçage : l'absence de config fait échouer le calcul bruyamment
 * (panne sûre — ADR-016 §10). Action la plus sensible du lot → réservée ADMIN_FINANCE + audit nominatif
 * (contrôle d'accès posé au contrôleur).
 */
@Service
public class ServiceCommissionConfig {

    private final CommissionConfigRepository depot;
    private final ServiceAudit audit;

    public ServiceCommissionConfig(CommissionConfigRepository depot, ServiceAudit audit) {
        this.depot = depot;
        this.audit = audit;
    }

    /**
     * Résout le taux applicable à un établissement : taux ouvert spécifique s'il existe, sinon taux
     * ouvert par défaut de la plateforme. Absence des deux → échec bruyant (jamais de 0 % implicite).
     */
    @Transactional(readOnly = true)
    public CommissionConfig resoudre(String etablissementRef) {
        return depot.findByEtablissementRefAndValideAuIsNull(etablissementRef)
                .or(depot::findByEtablissementRefIsNullAndValideAuIsNull)
                .orElseThrow(() -> new IllegalStateException(
                        "Aucun taux de commission configuré (ni pour l'établissement, ni par défaut). "
                        + "Poser un taux avant tout reversement (ADR-016 §10)."));
    }

    /**
     * Ouvre un nouveau taux pour un périmètre ({@code etablissementRef} null = défaut plateforme),
     * en clôturant le taux ouvert précédent au même instant (non-chevauchement). Append-only : rien
     * n'est réécrit.
     */
    @Transactional
    public CommissionConfig ouvrir(String etablissementRef, int tauxBps, String motif, String acteur) {
        if (tauxBps < 0 || tauxBps > 10000) {
            throw new IllegalArgumentException("Taux de commission hors bornes [0,10000] : " + tauxBps);
        }
        Instant maintenant = Instant.now();
        Optional<CommissionConfig> ouvertActuel = etablissementRef == null
                ? depot.findByEtablissementRefIsNullAndValideAuIsNull()
                : depot.findByEtablissementRefAndValideAuIsNull(etablissementRef);

        java.util.UUID remplaceId = null;
        if (ouvertActuel.isPresent()) {
            CommissionConfig ancien = ouvertActuel.get();
            ancien.cloturer(maintenant);
            depot.save(ancien);
            remplaceId = ancien.getId();
        }

        CommissionConfig nouveau = depot.save(new CommissionConfig(
                etablissementRef, tauxBps, maintenant, motif, remplaceId, acteur));
        audit.enregistrer("SettlementCommissionRateSet", "commission_config", nouveau.getId().toString(),
                Map.of("etablissement", etablissementRef == null ? "PLATEFORME" : etablissementRef,
                        "tauxBps", tauxBps, "acteur", acteur, "remplace", remplaceId == null ? "" : remplaceId.toString()));
        return nouveau;
    }

    @Transactional(readOnly = true)
    public Optional<CommissionConfig> courantPour(String etablissementRef) {
        return etablissementRef == null
                ? depot.findByEtablissementRefIsNullAndValideAuIsNull()
                : depot.findByEtablissementRefAndValideAuIsNull(etablissementRef);
    }

    @Transactional(readOnly = true)
    public List<CommissionConfig> tout() {
        return depot.findAll();
    }
}
