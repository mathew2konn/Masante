package ci.masante.payment.service;

import ci.masante.payment.domain.coverage.MoteurPriseEnCharge;
import ci.masante.payment.domain.coverage.RequeteCouverture;
import ci.masante.payment.domain.coverage.ResultatCouverture;
import org.springframework.stereotype.Service;

/**
 * Point d'entrée applicatif du calcul de prise en charge (CDC_06 §8). Délègue au moteur pur
 * {@link MoteurPriseEnCharge}. Aucun calcul n'est fait ailleurs que dans le domaine (frontière).
 *
 * <p>P5.1 : quote sans état (pas de persistance). L'historisation des dossiers de prise en charge
 * (InsuranceClaim / remboursements CNAM) est un incrément ultérieur (§8.1/§8.2).</p>
 */
@Service
public class ServicePriseEnCharge {

    public ResultatCouverture calculer(RequeteCouverture requete) {
        return MoteurPriseEnCharge.calculer(requete);
    }
}
