package ci.masante.payment.domain.carte.simulated;

import ci.masante.payment.domain.carte.DemandeCarte;
import ci.masante.payment.domain.carte.EvenementWebhook;
import ci.masante.payment.domain.carte.IssuePasserelle;
import ci.masante.payment.domain.carte.ModaliteCarte;
import ci.masante.payment.domain.carte.Montant;
import ci.masante.payment.domain.carte.PasserelleCarte;
import ci.masante.payment.domain.carte.ResultatCapture;
import ci.masante.payment.domain.carte.ResultatDebitRecurrent;
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
 * Passerelle carte SIMULÉE — modalité REDIRIGEE (modèle CinetPay/PayDunya/Paystack/Flutterwave).
 * DÉTERMINISTE : le comportement dépend de la {@code referenceClient} de test ; le scénario est encodé
 * dans la {@code refPasserelle}. Le résultat arrive normalement par WEBHOOK (§7.3), {@code recupererStatut}
 * restant le filet de sécurité (source de vérité).
 *
 * <table>
 *   <tr><td>red_test_succes</td>  <td>RedirectionRequise → webhook de succès (AUTORISE)</td></tr>
 *   <tr><td>red_test_abandon</td> <td>RedirectionRequise, aucun webhook → EN_ATTENTE (job → EXPIREE)</td></tr>
 *   <tr><td>red_test_refus</td>   <td>RedirectionRequise → webhook de refus (REFUSE)</td></tr>
 *   <tr><td>red_test_timeout</td> <td>RedirectionRequise → webhook de succès APRÈS expiration (rejeté)</td></tr>
 * </table>
 */
@Component
public class AdaptateurCarteSimuleRedirige implements PasserelleCarte {

    public static final String PSP = "sim_redirige";
    static final String SECRET_DEV = "dev-hmac-sim_redirige";

    private final ObjectMapper json;
    private final SecureRandom random = new SecureRandom();

    public AdaptateurCarteSimuleRedirige(ObjectMapper json) {
        this.json = json;
    }

    @Override
    public String psp() {
        return PSP;
    }

    @Override
    public ModaliteCarte modalite() {
        return ModaliteCarte.REDIRIGEE;
    }

    @Override
    public ResultatInitiation initier(DemandeCarte demande) {
        String ref = demande.referenceClient() == null ? "" : demande.referenceClient();
        return switch (ref) {
            case "red_test_succes" ->
                    redirection("SUCCES", Instant.now().plus(15, ChronoUnit.MINUTES));
            case "red_test_abandon" ->
                    redirection("ABANDON", Instant.now().plus(15, ChronoUnit.MINUTES));
            case "red_test_refus" ->
                    redirection("REFUS", Instant.now().plus(15, ChronoUnit.MINUTES));
            case "red_test_timeout" ->
                    redirection("TIMEOUT", Instant.now().plus(15, ChronoUnit.MINUTES));
            default ->
                    new ResultatInitiation.Refusee("unknown_reference", "Référence de test inconnue.");
        };
    }

    @Override
    public StatutPasserelle recupererStatut(String refPasserelle) {
        return switch (scenario(refPasserelle)) {
            case "SUCCES", "TIMEOUT" ->
                    new StatutPasserelle(IssuePasserelle.AUTORISE, ntid(), "MASTERCARD", "4444", 6, 2029, null);
            case "REFUS" ->
                    new StatutPasserelle(IssuePasserelle.REFUSE, null, null, null, null, null, "gateway_declined");
            default -> // ABANDON (et tout ce qui n'est pas revenu) : toujours en attente
                    StatutPasserelle.enAttente();
        };
    }

    @Override
    public ResultatCapture capturer(String refPasserelle, Montant montant) {
        return ResultatCapture.ok();
    }

    @Override
    public ResultatDebitRecurrent debiterRecurrent(String token, String networkTransactionId, Montant montant,
                                                    String referenceMandat) {
        // DÉTERMINISTE : un montant se terminant par 99 (unité mineure) simule un refus (fonds insuffisants).
        if (montant.valeur() % 100 == 99) {
            return ResultatDebitRecurrent.refuse("SIMMIT-REFUS-" + rand(), "insufficient_funds");
        }
        return ResultatDebitRecurrent.reussi("SIMMIT-OK-" + rand());
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

    private ResultatInitiation.RedirectionRequise redirection(String scenario, Instant expireLe) {
        String ref = "SIMRD-" + scenario + "-" + rand();
        return new ResultatInitiation.RedirectionRequise(ref,
                "https://sim-psp.local/pay/" + ref, expireLe);
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

    private String rand() {
        StringBuilder sb = new StringBuilder(12);
        String alphabet = "ABCDEFGHJKMNPQRSTUVWXYZ23456789";
        for (int i = 0; i < 12; i++) {
            sb.append(alphabet.charAt(random.nextInt(alphabet.length())));
        }
        return sb.toString();
    }
}
