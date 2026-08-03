package ci.masante.payment.domain.gateway;

import org.springframework.stereotype.Component;

import java.util.List;

/**
 * Sélectionne la passerelle capable de traiter un canal (CDC_06 §3.3 — OCP).
 *
 * <p>Spring injecte <b>toutes</b> les implémentations de {@link PasserellePaiement}. La première qui
 * déclare {@code supporte(canal)} est retenue. Ajouter un opérateur = ajouter un bean : zéro
 * modification ici, zéro {@code switch} sur le canal.</p>
 */
@Component
public class RegistrePasserelles {

    private final List<PasserellePaiement> passerelles;

    public RegistrePasserelles(List<PasserellePaiement> passerelles) {
        this.passerelles = passerelles;
    }

    public PasserellePaiement pour(String canal) {
        return passerelles.stream()
                .filter(p -> p.supporte(canal))
                .findFirst()
                .orElseThrow(() -> new CanalNonSupporteException(canal));
    }
}
