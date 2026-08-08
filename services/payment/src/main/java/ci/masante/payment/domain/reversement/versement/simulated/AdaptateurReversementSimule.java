package ci.masante.payment.domain.reversement.versement.simulated;

import ci.masante.payment.domain.model.TypeDestination;
import ci.masante.payment.domain.reversement.versement.DemandeDecaissement;
import ci.masante.payment.domain.reversement.versement.PasserelleReversement;
import ci.masante.payment.domain.reversement.versement.ResultatDecaissement;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

import java.security.SecureRandom;

/**
 * Passerelle de décaissement SIMULÉE (CDC_06 — FT5 : aucune passerelle de versement réelle accessible).
 * Elle ne verse RIEN et couvre tous les types de destination du MVP. Le jour où un adaptateur réel
 * (OrangeMoneyReversementAdapter, VirementBancaireAdapter…) est ajouté pour un type, il le déclarera dans
 * {@link #supporte} et sera choisi en priorité par le registre — sans toucher à cette classe.
 *
 * <p><b>Déterministe (dev)</b> — le résultat est décidé par la passerelle, jamais par l'appelant (miroir
 * « 3DS jamais côté client », P5.4a). Convention de test encodée dans le compte destination :</p>
 * <table>
 *   <tr><td>MSISDN/IBAN se terminant par {@code 99}</td><td>ÉCHOUE (refus opérateur simulé)</td></tr>
 *   <tr><td>tout autre compte</td><td>EXÉCUTÉ</td></tr>
 * </table>
 *
 * <p><b>Frais = DONNÉE</b> : {@code masante.payment.reversement.frais-simules-bps} (défaut 0) → frais
 * rapportés comme le ferait un vrai PSP, jamais un nombre magique. Le scénario est encodé dans la
 * {@code referencePasserelle} (adaptateur SANS état) → {@link #statut(String)} reste la vérité serveur.</p>
 */
@Component
public class AdaptateurReversementSimule implements PasserelleReversement {

    private final int fraisBps;
    private final SecureRandom random = new SecureRandom();

    public AdaptateurReversementSimule(
            @Value("${masante.payment.reversement.frais-simules-bps:0}") int fraisBps) {
        this.fraisBps = fraisBps;
    }

    @Override
    public boolean supporte(TypeDestination type) {
        return type == TypeDestination.MOBILE_MONEY || type == TypeDestination.VIREMENT_BANCAIRE;
    }

    @Override
    public ResultatDecaissement verser(DemandeDecaissement demande) {
        String compte = demande.destinationClair() == null ? "" : demande.destinationClair();
        if (compte.endsWith("99")) {
            return ResultatDecaissement.echoue(ref("ECHEC"), "Refus opérateur simulé (compte de test en 99).");
        }
        long frais = Math.floorDiv(demande.montant() * (long) fraisBps, 10_000L);
        return ResultatDecaissement.execute(ref("EXEC"), frais);
    }

    @Override
    public ResultatDecaissement statut(String referencePasserelle) {
        return switch (scenario(referencePasserelle)) {
            case "EXEC" -> ResultatDecaissement.execute(referencePasserelle, 0L);
            case "ECHEC" -> ResultatDecaissement.echoue(referencePasserelle, "Refus opérateur simulé.");
            default -> ResultatDecaissement.echoue(referencePasserelle, "Référence de décaissement inconnue.");
        };
    }

    private String ref(String scenario) {
        return "SIMRV-" + scenario + "-" + rand();
    }

    private static String scenario(String referencePasserelle) {
        if (referencePasserelle == null) {
            return "";
        }
        String[] parts = referencePasserelle.split("-");
        return parts.length >= 2 ? parts[1] : "";
    }

    private String rand() {
        StringBuilder sb = new StringBuilder(12);
        String alphabet = "ABCDEFGHJKMNPQRSTUVWXYZ23456789";
        for (int i = 0; i < 12; i++) {
            sb.append(alphabet.charAt(random.nextInt(alphabet.length())));
        }
        return sb.toString();
    }
}
