package ci.masante.payment.web;

import ci.masante.payment.domain.billing.FacturationInvalideException;
import ci.masante.payment.domain.carte.PasserelleInconnue;
import ci.masante.payment.domain.carte.TransitionCarteIllegale;
import ci.masante.payment.domain.mandat.TransitionMandatIllegale;
import ci.masante.payment.domain.coverage.CouvertureInvalideException;
import ci.masante.payment.domain.fraud.FraudSuspecteeException;
import ci.masante.payment.domain.gateway.CanalNonSupporteException;
import ci.masante.payment.domain.reward.CampagneInvalideException;
import ci.masante.payment.domain.reward.CashbackInvalideException;
import ci.masante.payment.domain.reversement.ReversementInvalideException;
import ci.masante.payment.domain.reward.PlafondRecompenseException;
import ci.masante.payment.domain.statemachine.TransitionInvalideException;
import ci.masante.payment.domain.wallet.ChallengeRequisException;
import ci.masante.payment.domain.wallet.LimiteDepasseeException;
import ci.masante.payment.domain.wallet.OperationWalletInvalideException;
import ci.masante.payment.domain.wallet.OtpInvalideException;
import ci.masante.payment.domain.wallet.OtpRequisException;
import ci.masante.payment.domain.wallet.PinInvalideException;
import ci.masante.payment.domain.wallet.PinVerrouilleException;
import ci.masante.payment.domain.wallet.SoldeInsuffisantException;
import ci.masante.payment.domain.wallet.WalletGeleException;
import ci.masante.payment.service.ActeurRequisException;
import ci.masante.payment.service.AlerteFraudeIntrouvableException;
import ci.masante.payment.service.AvoirIntrouvableException;
import ci.masante.payment.service.CarteIntrouvableException;
import ci.masante.payment.service.CarteTransactionIntrouvableException;
import ci.masante.payment.service.ControleIntrouvableException;
import ci.masante.payment.service.ConflitIdempotenceException;
import ci.masante.payment.service.FactureIntrouvableException;
import ci.masante.payment.service.FraudeInjoignableException;
import ci.masante.payment.service.MandatIntrouvableException;
import ci.masante.payment.service.OperationCarteInvalideException;
import ci.masante.payment.service.OperationMandatInvalideException;
import ci.masante.payment.service.PaiementIntrouvableException;
import ci.masante.payment.service.PrincipalInvalideException;
import ci.masante.payment.service.ReversementIntrouvableException;
import ci.masante.payment.service.RoleInsuffisantException;
import ci.masante.payment.service.WalletIntrouvableException;
import ci.masante.payment.service.WebhookInvalideException;
import org.springframework.http.HttpStatus;
import org.springframework.http.ProblemDetail;
import org.springframework.web.bind.MissingRequestHeaderException;
import org.springframework.web.bind.MethodArgumentNotValidException;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;

import java.util.stream.Collectors;

/**
 * Traduit les exceptions métier en réponses HTTP normalisées (RFC 7807 ProblemDetail).
 * Aucune règle métier ici : simple correspondance exception → code.
 */
@RestControllerAdvice
public class GestionErreurs {

    /** Transition d'état (générique ou carte) interdite, ou remboursement d'un paiement non abouti → 409. */
    @ExceptionHandler({TransitionInvalideException.class, TransitionCarteIllegale.class,
            TransitionMandatIllegale.class, IllegalStateException.class})
    public ProblemDetail conflit(RuntimeException ex) {
        return probleme(HttpStatus.CONFLICT, ex.getMessage());
    }

    /** Verrou d'idempotence détenu (traitement concurrent en cours) → 409. */
    @ExceptionHandler(ConflitIdempotenceException.class)
    public ProblemDetail idempotence(ConflitIdempotenceException ex) {
        return probleme(HttpStatus.CONFLICT, ex.getMessage());
    }

    /** Paiement, facture, avoir, portefeuille, transaction ou carte introuvable → 404. */
    @ExceptionHandler({PaiementIntrouvableException.class, FactureIntrouvableException.class,
            AvoirIntrouvableException.class, WalletIntrouvableException.class,
            AlerteFraudeIntrouvableException.class, ControleIntrouvableException.class,
            CarteTransactionIntrouvableException.class, CarteIntrouvableException.class,
            ReversementIntrouvableException.class, MandatIntrouvableException.class})
    public ProblemDetail introuvable(RuntimeException ex) {
        return probleme(HttpStatus.NOT_FOUND, ex.getMessage());
    }

    /** Entrée de calcul de reversement invalide (taux hors bornes, report positif, montant négatif) → 422. */
    @ExceptionHandler(ReversementInvalideException.class)
    public ProblemDetail reversementInvalide(ReversementInvalideException ex) {
        return probleme(HttpStatus.UNPROCESSABLE_ENTITY, ex.getMessage());
    }

    /** Rôle insuffisant pour une action sensible (taux de commission, destination) → 403. Générique. */
    @ExceptionHandler(RoleInsuffisantException.class)
    public ProblemDetail roleInsuffisant(RoleInsuffisantException ex) {
        return probleme(HttpStatus.FORBIDDEN, ex.getMessage());
    }

    /** fraud-detection-service injoignable/illisible lors d'un routage d'alerte → 502. Aucune alerte inventée. */
    @ExceptionHandler(FraudeInjoignableException.class)
    public ProblemDetail fraudeInjoignable(FraudeInjoignableException ex) {
        return probleme(HttpStatus.BAD_GATEWAY, ex.getMessage());
    }

    /** Principal signé invalide (signature/fraîcheur/rejeu/liaison requête) → 401. Générique (anti-fuite). */
    @ExceptionHandler(PrincipalInvalideException.class)
    public ProblemDetail principalInvalide(PrincipalInvalideException ex) {
        return probleme(HttpStatus.UNAUTHORIZED, ex.getMessage());
    }

    /** Destination de reversement au format invalide (MSISDN/IBAN) → 422. */
    @ExceptionHandler(ci.masante.payment.domain.reversement.DestinationInvalideException.class)
    public ProblemDetail destinationInvalide(ci.masante.payment.domain.reversement.DestinationInvalideException ex) {
        return probleme(HttpStatus.UNPROCESSABLE_ENTITY, ex.getMessage());
    }

    /** Canal/PSP non supporté, entrée de couverture/facturation/wallet/cashback invalide → 400. */
    @ExceptionHandler({CanalNonSupporteException.class, PasserelleInconnue.class,
            CouvertureInvalideException.class, FacturationInvalideException.class,
            OperationWalletInvalideException.class, CashbackInvalideException.class})
    public ProblemDetail requeteInvalide(RuntimeException ex) {
        return probleme(HttpStatus.BAD_REQUEST, ex.getMessage());
    }

    /** Opération carte invalide dans l'état courant (remboursement &gt; capturé, devise, capture) → 422. */
    @ExceptionHandler({OperationCarteInvalideException.class, OperationMandatInvalideException.class})
    public ProblemDetail operationCarteInvalide(RuntimeException ex) {
        return probleme(HttpStatus.UNPROCESSABLE_ENTITY, ex.getMessage());
    }

    /**
     * Paiement en ligne indisponible pour ce montant (lot 7, §7.5) → <b>422</b>.
     *
     * <p>Ce n'est pas une panne, et le code de statut doit le dire : le paiement sur place reste la
     * voie normale sous le plancher. Sans ce mappage, le refus remontait en <b>500</b> — le partenaire
     * aurait lu « le service est cassé » là où il fallait lire « payez au guichet », et aurait
     * probablement réessayé.</p>
     */
    @ExceptionHandler(ci.masante.payment.service.PaiementEnLigneIndisponibleException.class)
    public ProblemDetail paiementEnLigneIndisponible(RuntimeException ex) {
        return probleme(HttpStatus.UNPROCESSABLE_ENTITY, ex.getMessage());
    }

    /**
     * Aucun identifiant marchand actif pour cet établissement (montage A) → <b>409</b>.
     *
     * <p>Refus explicite, jamais un repli sur un autre compte : basculer ferait arriver l'argent d'un
     * partenaire sur le compte d'un autre.</p>
     */
    @ExceptionHandler({ci.masante.payment.domain.gateway.geniuspay.MarchandIntrouvableException.class,
            ci.masante.payment.service.SecretMarchandAbsentException.class})
    public ProblemDetail marchandIntrouvable(RuntimeException ex) {
        return probleme(HttpStatus.CONFLICT, ex.getMessage());
    }

    /**
     * Le prestataire a refusé, ou n'a pas répondu → <b>502</b>.
     *
     * <p>Les deux se distinguent dans le <b>détail</b>, jamais dans le statut : une incertitude ne se
     * présente pas comme un refus. Et surtout, aucun des deux ne déclenche de rejeu — c'est la règle
     * la plus importante du lot (§7.4).</p>
     */
    @ExceptionHandler({ci.masante.payment.domain.gateway.geniuspay.GeniusPayException.class,
            ci.masante.payment.domain.gateway.geniuspay.GeniusPayInjoignableException.class})
    public ProblemDetail prestatairePaiement(RuntimeException ex) {
        return probleme(HttpStatus.BAD_GATEWAY, ex.getMessage());
    }

    /** Campagne inexistante/inactive/hors période → 409. */
    @ExceptionHandler(CampagneInvalideException.class)
    public ProblemDetail campagne(CampagneInvalideException ex) {
        return probleme(HttpStatus.CONFLICT, ex.getMessage());
    }

    /** Plafond de cashback (budget/wallet/jour) atteint → 422. */
    @ExceptionHandler(PlafondRecompenseException.class)
    public ProblemDetail plafondRecompense(PlafondRecompenseException ex) {
        return probleme(HttpStatus.UNPROCESSABLE_ENTITY, ex.getMessage());
    }

    /** Acte de création monétaire sans acteur identifié → 401. */
    @ExceptionHandler(ActeurRequisException.class)
    public ProblemDetail acteurRequis(ActeurRequisException ex) {
        return probleme(HttpStatus.UNAUTHORIZED, ex.getMessage());
    }

    /** Webhook carte rejeté (signature/fraîcheur/parse) → 401. Message GÉNÉRIQUE (anti-fuite §7.3). */
    @ExceptionHandler(WebhookInvalideException.class)
    public ProblemDetail webhookInvalide(WebhookInvalideException ex) {
        return probleme(HttpStatus.UNAUTHORIZED, ex.getMessage());
    }

    /**
     * Violation de contrainte de données (ex. unicité : une campagne active existe déjà pour ce type
     * d'opération source) → 409. Un conflit prévisible ne doit pas remonter en 500.
     */
    @ExceptionHandler(org.springframework.dao.DataIntegrityViolationException.class)
    public ProblemDetail conflitDonnees(org.springframework.dao.DataIntegrityViolationException ex) {
        return probleme(HttpStatus.CONFLICT,
                "Conflit de données : contrainte violée (ex. campagne active déjà existante pour ce type).");
    }

    /** Solde insuffisant ou portefeuille gelé → 409. */
    @ExceptionHandler({SoldeInsuffisantException.class, WalletGeleException.class})
    public ProblemDetail conflitWallet(RuntimeException ex) {
        return probleme(HttpStatus.CONFLICT, ex.getMessage());
    }

    /** PIN absent/incorrect ou OTP absent/incorrect (§6.4) → 401. */
    @ExceptionHandler({PinInvalideException.class, OtpInvalideException.class, OtpRequisException.class})
    public ProblemDetail authentificationWallet(RuntimeException ex) {
        return probleme(HttpStatus.UNAUTHORIZED, ex.getMessage());
    }

    /** PIN verrouillé après trop d'échecs (§6.4) → 423 Locked. */
    @ExceptionHandler(PinVerrouilleException.class)
    public ProblemDetail pinVerrouille(PinVerrouilleException ex) {
        return probleme(HttpStatus.LOCKED, ex.getMessage());
    }

    /** Limite de montant (opération/jour/mois, §6.4) dépassée → 422. */
    @ExceptionHandler(LimiteDepasseeException.class)
    public ProblemDetail limiteDepassee(LimiteDepasseeException ex) {
        return probleme(HttpStatus.UNPROCESSABLE_ENTITY, ex.getMessage());
    }

    /** Suspicion de fraude : opération bloquée et wallet gelé (§6.4) → 409. Message générique. */
    @ExceptionHandler(FraudSuspecteeException.class)
    public ProblemDetail fraudeSuspectee(FraudSuspecteeException ex) {
        return probleme(HttpStatus.CONFLICT, ex.getMessage());
    }

    /** Vérification renforcée (OTP) requise avant de poursuivre (§6.4) → 401. Message générique. */
    @ExceptionHandler(ChallengeRequisException.class)
    public ProblemDetail challengeRequis(ChallengeRequisException ex) {
        return probleme(HttpStatus.UNAUTHORIZED, ex.getMessage());
    }

    /** En-tête obligatoire manquant (Idempotency-Key) → 400. */
    @ExceptionHandler(MissingRequestHeaderException.class)
    public ProblemDetail enteteManquant(MissingRequestHeaderException ex) {
        return probleme(HttpStatus.BAD_REQUEST, "En-tête obligatoire manquant : " + ex.getHeaderName());
    }

    /** Argument invalide (fenêtre de période, motif manquant, taux hors bornes) → 400. */
    @ExceptionHandler(IllegalArgumentException.class)
    public ProblemDetail argumentInvalide(IllegalArgumentException ex) {
        return probleme(HttpStatus.BAD_REQUEST, ex.getMessage());
    }

    /** Validation de la requête (Bean Validation) → 400 avec le détail des champs. */
    @ExceptionHandler(MethodArgumentNotValidException.class)
    public ProblemDetail validation(MethodArgumentNotValidException ex) {
        String details = ex.getBindingResult().getFieldErrors().stream()
                .map(f -> f.getField() + " : " + f.getDefaultMessage())
                .collect(Collectors.joining(" ; "));
        return probleme(HttpStatus.BAD_REQUEST, details.isBlank() ? "Requête invalide." : details);
    }

    private ProblemDetail probleme(HttpStatus statut, String detail) {
        ProblemDetail pd = ProblemDetail.forStatusAndDetail(statut, detail);
        pd.setTitle(statut.getReasonPhrase());
        return pd;
    }
}
