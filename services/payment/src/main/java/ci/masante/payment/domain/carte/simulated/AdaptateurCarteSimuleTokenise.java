package ci.masante.payment.domain.carte.simulated;

import ci.masante.payment.domain.carte.DemandeCarte;
import ci.masante.payment.domain.carte.EvenementWebhook;
import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.ModaliteCarte;
import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.carte.PasserelleCarte;
import ci.masante.payment.domain.carte.ResultatCapture;
import ci.masante.payment.domain.carte.ResultatInitiation;
import ci.masante.payment.domain.carte.StatutPasserelle;
import ci.masante.payment.domain.gateway.ResultatRemboursement;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.stereotype.Component;

import java.security.SecureRandom;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.Map;

/**
 * Passerelle carte SIMULÉE — modalité TOKENISEE (modèle Stripe/Adyen). DÉTERMINISTE : le comportement
 * dépend de la {@code referenceClient} de test. Le scénario est encodé dans la {@code refPasserelle}
 * (adaptateur SANS état) → {@code recupererStatut} reste la vérité côté serveur.
 *
 * <table>
 *   <tr><td>tok_test_frictionless</td><td>succès direct (Frictionless → AUTORISE)</td></tr>
 *   <tr><td>tok_test_challenge</td>   <td>DefiRequis, puis AUTORISE à la finalisation</td></tr>
 *   <tr><td>tok_test_refus</td>       <td>Refusee à l'initiation</td></tr>
 *   <tr><td>tok_test_authfail</td>    <td>DefiRequis, puis REFUSE à la finalisation</td></tr>
 *   <tr><td>tok_test_expire</td>      <td>DefiRequis avec TTL déjà dépassé</td></tr>
 * </table>
 */
@Component
public class AdaptateurCarteSimuleTokenise implements PasserelleCarte {

    public static final String PSP = "sim_tokenise";
    /** Secret HMAC de DÉV (jamais un vrai secret ; visible du test). Secret réel par PSP = config/HSM « prêt à activer ». */
    static final String SECRET_DEV = "dev-hmac-sim_tokenise";

    private final ObjectMapper json;
    private final SecureRandom random = new SecureRandom();

    public AdaptateurCarteSimuleTokenise(ObjectMapper json) {
        this.json = json;
    }

    @Override
    public String psp() {
        return PSP;
    }

    @Override
    public ModaliteCarte modalite() {
        return ModaliteCarte.TOKENISEE;
    }

    @Override
    public ResultatInitiation initier(DemandeCarte demande) {
        String ref = demande.referenceClient() == null ? "" : demande.referenceClient();
        return switch (ref) {
            case "tok_test_frictionless" ->
                    new ResultatInitiation.Frictionless(refP("FRICTIONLESS"), ntid(), "VISA", "4242", 12, 2030);
            case "tok_test_challenge" ->
                    new ResultatInitiation.DefiRequis(refP("CHALLENGE"), challenge(),
                            Instant.now().plus(15, ChronoUnit.MINUTES));
            case "tok_test_authfail" ->
                    new ResultatInitiation.DefiRequis(refP("AUTHFAIL"), challenge(),
                            Instant.now().plus(15, ChronoUnit.MINUTES));
            case "tok_test_expire" ->
                    new ResultatInitiation.DefiRequis(refP("EXPIRE"), challenge(),
                            Instant.now().minus(1, ChronoUnit.MINUTES));
            case "tok_test_refus" ->
                    new ResultatInitiation.Refusee("card_declined", "Paiement refusé par l'émetteur.");
            default ->
                    new ResultatInitiation.Refusee("unknown_reference", "Référence de test inconnue.");
        };
    }

    @Override
    public StatutPasserelle recupererStatut(String refPasserelle) {
        return switch (scenario(refPasserelle)) {
            case "FRICTIONLESS", "CHALLENGE" ->
                    new StatutPasserelle(IssuePasserelle.AUTORISE, ntid(), "VISA", "4242", 12, 2030, null);
            case "AUTHFAIL" ->
                    new StatutPasserelle(IssuePasserelle.REFUSE, null, null, null, null, null, "authentication_failed");
            case "EXPIRE" ->
                    new StatutPasserelle(IssuePasserelle.EXPIRE, null, null, null, null, null, "challenge_expired");
            default -> StatutPasserelle.enAttente();
        };
    }

    @Override
    public ResultatCapture capturer(String refPasserelle, Montant montant) {
        return ResultatCapture.ok(); // une autorisation valide se capture en simulation
    }

    @Override
    public ResultatRemboursement rembourser(String refPasserelle, Montant montant, String motif) {
        return new ResultatRemboursement(true, "SIMRF-" + rand(), "Remboursement simulé accepté.");
    }

    @Override
    public boolean verifierSignature(byte[] corpsBrut, Map<String, String> entetes) {
        return SignatureHmac.verifier(corpsBrut, SECRET_DEV, SignatureHmac.entete(entetes, "X-Signature"));
    }

    @Override
    public EvenementWebhook parserEvenement(byte[] corpsBrut) {
        return AnalyseurWebhookSimule.parser(json, corpsBrut);
    }

    private String refP(String scenario) {
        return "SIMTK-" + scenario + "-" + rand();
    }

    private static String scenario(String refPasserelle) {
        if (refPasserelle == null) {
            return "";
        }
        String[] parts = refPasserelle.split("-");
        return parts.length >= 2 ? parts[1] : "";
    }

    private String ntid() {
        return "NTID-" + rand();
    }

    private String challenge() {
        return "chg_" + rand();
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
