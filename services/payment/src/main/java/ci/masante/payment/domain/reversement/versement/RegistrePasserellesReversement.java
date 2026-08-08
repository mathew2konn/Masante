package ci.masante.payment.domain.reversement.versement;

import ci.masante.payment.domain.model.TypeDestination;
import ci.masante.payment.domain.gateway.CanalNonSupporteException;
import org.springframework.stereotype.Component;

import java.util.List;

/**
 * Dispatch des passerelles de décaissement par type de destination (CDC_06 §11 — OCP, miroir de
 * {@code RegistrePasserelles}). Spring injecte toutes les {@link PasserelleReversement} ; la première qui
 * {@code supporte(type)} l'emporte → un adaptateur réel déclaré pour un type sera choisi en priorité sans
 * toucher au reste. <b>Aucun {@code if type == …}</b> (interdit #5).
 */
@Component
public class RegistrePasserellesReversement {

    private final List<PasserelleReversement> passerelles;

    public RegistrePasserellesReversement(List<PasserelleReversement> passerelles) {
        this.passerelles = passerelles;
    }

    public PasserelleReversement pour(TypeDestination type) {
        return passerelles.stream()
                .filter(p -> p.supporte(type))
                .findFirst()
                .orElseThrow(() -> new CanalNonSupporteException("décaissement/" + type));
    }
}
