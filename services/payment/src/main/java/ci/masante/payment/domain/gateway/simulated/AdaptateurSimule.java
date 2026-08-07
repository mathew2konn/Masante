package ci.masante.payment.domain.gateway.simulated;

import ci.masante.payment.domain.gateway.PasserellePaiement;
import ci.masante.payment.domain.gateway.RequetePaiement;
import ci.masante.payment.domain.gateway.RequeteRemboursement;
import ci.masante.payment.domain.gateway.ResultatPaiement;
import ci.masante.payment.domain.gateway.ResultatRemboursement;
import ci.masante.payment.domain.model.PaiementStatut;
import org.springframework.stereotype.Component;

import java.security.SecureRandom;
import java.util.Set;

/**
 * Passerelle SIMULÉE (CDC_06 — FT5 : aucune passerelle Mobile Money réelle accessible à ce projet).
 *
 * <p>Elle prend en charge tous les canaux du MVP et renvoie systématiquement un succès déterministe
 * avec une {@code referenceOperateur} factice {@code SIM-…}. Elle ne débite RIEN. Le jour où un vrai
 * adaptateur (OrangeMoneyAdapter…) est ajouté pour un canal, il le déclarera dans
 * {@link #supporte(String)} et sera choisi en priorité par le registre — sans toucher à cette classe.</p>
 */
@Component
public class AdaptateurSimule implements PasserellePaiement {

    /**
     * Canaux couverts par la simulation tant qu'aucun adaptateur réel n'est branché.
     * <p>Le canal {@code carte} a été RETIRÉ (P5.4a) : les paiements par carte sont orchestrés par
     * {@code ServiceCarte} via {@code RegistrePasserellesCarte} (3DS2 / autorisation / capture / webhook),
     * et non par ce dispatch générique. Une demande générique {@code canal=carte} est donc volontairement
     * rejetée (aucune passerelle générique ne la supporte).</p>
     */
    private static final Set<String> CANAUX = Set.of(
            "orange_money", "mtn_momo", "wave", "moov_money", "wallet", "especes"
    );

    private final SecureRandom random = new SecureRandom();

    @Override
    public boolean supporte(String canal) {
        return canal != null && CANAUX.contains(canal.toLowerCase());
    }

    @Override
    public ResultatPaiement payer(RequetePaiement requete) {
        return new ResultatPaiement(PaiementStatut.SUCCESS, refFactice(), "Paiement simulé accepté (aucun débit réel).");
    }

    @Override
    public ResultatPaiement statut(String referenceOperateur) {
        return new ResultatPaiement(PaiementStatut.SUCCESS, referenceOperateur, "Statut simulé.");
    }

    @Override
    public ResultatRemboursement rembourser(RequeteRemboursement requete) {
        return new ResultatRemboursement(true, requete.referenceOperateur(), "Remboursement simulé accepté.");
    }

    private String refFactice() {
        StringBuilder sb = new StringBuilder("SIM-");
        String alphabet = "ABCDEFGHJKMNPQRSTUVWXYZ23456789";
        for (int i = 0; i < 12; i++) {
            sb.append(alphabet.charAt(random.nextInt(alphabet.length())));
        }
        return sb.toString();
    }
}
