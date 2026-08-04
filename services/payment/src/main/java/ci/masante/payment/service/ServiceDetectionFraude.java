package ci.masante.payment.service;

import ci.masante.payment.domain.fraud.ParametresFraude;
import ci.masante.payment.domain.fraud.ResultatFraude;
import ci.masante.payment.domain.fraud.ReglesDetectionFraude;
import ci.masante.payment.domain.fraud.SignauxFraude;
import ci.masante.payment.domain.model.FraudAlerte;
import ci.masante.payment.domain.model.StatutAlerteFraude;
import ci.masante.payment.domain.model.Wallet;
import ci.masante.payment.domain.model.WalletStatut;
import ci.masante.payment.repository.EntreeAuditRepository;
import ci.masante.payment.repository.FraudAlerteRepository;
import ci.masante.payment.repository.WalletEntryRepository;
import ci.masante.payment.repository.WalletOperationRepository;
import ci.masante.payment.repository.WalletRepository;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Propagation;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Détection de fraude par règles + gel sur suspicion (CDC_06 §6.4). <b>Frontière</b> : scoring et
 * décision = backend seul, par règles déterministes (pas d'IA qui décide seule). Seuils = données.
 *
 * <p><b>Deux temps distincts</b> (voir {@code ServiceWallet}) : l'<b>évaluation</b> se fait DANS la
 * transaction verrouillée de l'opération (concurrence sérialisée par le verrou du wallet, donc
 * vélocité non contournable) ; le <b>gel + alerte + audit</b> sur palier GEL se font APRÈS, dans une
 * transaction propre ({@code traiterSuspicion}, {@link Propagation#REQUIRES_NEW}), une fois la
 * transaction de l'opération annulée et le verrou relâché — sinon l'exception annulerait le gel et un
 * {@code REQUIRES_NEW} sur la ligne verrouillée provoquerait un interblocage.</p>
 */
@Service
public class ServiceDetectionFraude {

    private final WalletOperationRepository operations;
    private final WalletEntryRepository entries;
    private final EntreeAuditRepository audits;
    private final WalletRepository wallets;
    private final FraudAlerteRepository alertes;
    private final ServiceAudit audit;
    private final ObjectMapper json;

    private final int velociteFenetre;
    private final int velociteMax;
    private final int cumulFenetre;
    private final long cumulMax;
    private final int pinFenetre;
    private final int pinMax;
    private final int poidsVelocite;
    private final int poidsCumul;
    private final int poidsPin;
    private final int seuilAlerte;
    private final int seuilChallenge;
    private final int seuilGel;
    private final int gelTtlHeures;

    public ServiceDetectionFraude(
            WalletOperationRepository operations, WalletEntryRepository entries,
            EntreeAuditRepository audits, WalletRepository wallets, FraudAlerteRepository alertes,
            ServiceAudit audit, ObjectMapper json,
            @Value("${masante.payment.wallet.fraude.velocite.fenetre-secondes:60}") int velociteFenetre,
            @Value("${masante.payment.wallet.fraude.velocite.max-operations:10}") int velociteMax,
            @Value("${masante.payment.wallet.fraude.montant.fenetre-secondes:3600}") int cumulFenetre,
            @Value("${masante.payment.wallet.fraude.montant.max-cumule:5000000}") long cumulMax,
            @Value("${masante.payment.wallet.fraude.pin.fenetre-secondes:3600}") int pinFenetre,
            @Value("${masante.payment.wallet.fraude.pin.echecs-recents-max:5}") int pinMax,
            @Value("${masante.payment.wallet.fraude.poids.velocite:50}") int poidsVelocite,
            @Value("${masante.payment.wallet.fraude.poids.cumul:30}") int poidsCumul,
            @Value("${masante.payment.wallet.fraude.poids.pin:30}") int poidsPin,
            @Value("${masante.payment.wallet.fraude.seuil-alerte:30}") int seuilAlerte,
            @Value("${masante.payment.wallet.fraude.seuil-challenge:50}") int seuilChallenge,
            @Value("${masante.payment.wallet.fraude.seuil-gel:80}") int seuilGel,
            @Value("${masante.payment.wallet.fraude.gel-ttl-heures:24}") int gelTtlHeures) {
        this.operations = operations;
        this.entries = entries;
        this.audits = audits;
        this.wallets = wallets;
        this.alertes = alertes;
        this.audit = audit;
        this.json = json;
        this.velociteFenetre = velociteFenetre;
        this.velociteMax = velociteMax;
        this.cumulFenetre = cumulFenetre;
        this.cumulMax = cumulMax;
        this.pinFenetre = pinFenetre;
        this.pinMax = pinMax;
        this.poidsVelocite = poidsVelocite;
        this.poidsCumul = poidsCumul;
        this.poidsPin = poidsPin;
        this.seuilAlerte = seuilAlerte;
        this.seuilChallenge = seuilChallenge;
        this.seuilGel = seuilGel;
        this.gelTtlHeures = gelTtlHeures;
    }

    public ParametresFraude parametres() {
        return new ParametresFraude(velociteMax, cumulMax, pinMax, poidsVelocite, poidsCumul, poidsPin,
                seuilAlerte, seuilChallenge, seuilGel);
    }

    /** Évalue le risque d'une opération sortante. Lu DANS la tx verrouillée de l'op (concurrence). */
    @Transactional(readOnly = true)
    public ResultatFraude evaluer(UUID walletId, long montant) {
        Instant now = Instant.now();
        int nbOps = operations.compteSortantesDepuis(walletId, now.minusSeconds(velociteFenetre));
        long cumule = entries.debitsDepuis(walletId, now.minusSeconds(cumulFenetre));
        int echecsPin = audits.compteEvenementDepuis("wallet", walletId.toString(), "WalletPinEchec",
                now.minusSeconds(pinFenetre));
        SignauxFraude signaux = new SignauxFraude(nbOps, cumule, echecsPin, montant);
        return ReglesDetectionFraude.evaluer(signaux, parametres());
    }

    /** Enregistre une alerte (paliers ALERTE/CHALLENGE) — rejoint la transaction de l'opération. */
    @Transactional
    public void enregistrerAlerte(UUID walletId, ResultatFraude rf, long montant) {
        creerAlerte(walletId, rf, montant);
    }

    /**
     * Palier GEL : transaction PROPRE (REQUIRES_NEW) exécutée APRÈS l'annulation de l'opération.
     * Gèle le wallet (TTL), crée l'alerte, audite {@code FraudSuspected} + {@code WalletFrozen}.
     * Idempotent : si une alerte est déjà OUVERTE, on n'en empile pas une seconde ni ne re-gèle.
     */
    @Transactional(propagation = Propagation.REQUIRES_NEW)
    public void traiterSuspicion(UUID walletId, ResultatFraude rf, long montant) {
        Wallet w = wallets.findByIdVerrouille(walletId)
                .orElseThrow(() -> new WalletIntrouvableException(walletId.toString()));
        if (w.getStatut() == WalletStatut.GELE) {
            return; // déjà gelé → ne pas re-geler ni empiler d'alerte (idempotent)
        }
        creerAlerte(walletId, rf, montant);
        audit.enregistrer("FraudSuspected", "wallet", walletId.toString(),
                Map.of("score", rf.score(), "palier", rf.palier().name(),
                        "motifs", rf.motifs().stream().map(Enum::name).toList()));
        w.gelerJusqua(Instant.now().plusSeconds((long) gelTtlHeures * 3600));
        wallets.save(w);
        audit.enregistrer("WalletFrozen", "wallet", walletId.toString(),
                Map.of("cause", "FRAUDE", "gelJusquA", String.valueOf(w.getGelJusquA())));
    }

    // --- revue des alertes --------------------------------------------------------------------

    @Transactional(readOnly = true)
    public List<FraudAlerte> alertesDe(UUID walletId) {
        return alertes.findByWalletIdOrderByCreatedAtDesc(walletId);
    }

    @Transactional(readOnly = true)
    public List<FraudAlerte> alertesOuvertes() {
        return alertes.findByStatutOrderByCreatedAtDesc(StatutAlerteFraude.OUVERTE);
    }

    /** Marque une alerte revue. Ne dégèle PAS le wallet (le dégel est une action distincte). */
    @Transactional
    public FraudAlerte marquerRevue(UUID alerteId, String revuePar) {
        FraudAlerte a = alertes.findById(alerteId)
                .orElseThrow(() -> new AlerteFraudeIntrouvableException(alerteId.toString()));
        a.marquerRevue(revuePar, Instant.now());
        alertes.save(a);
        audit.enregistrer("FraudAlertReviewed", "fraud_alerte", a.getId().toString(),
                Map.of("par", revuePar == null ? "" : revuePar));
        return a;
    }

    private void creerAlerte(UUID walletId, ResultatFraude rf, long montant) {
        alertes.save(new FraudAlerte(walletId, rf.score(), rf.palier().name(),
                serialiser(rf.motifs().stream().map(Enum::name).toList()),
                serialiser(parametres()), montant));
    }

    private String serialiser(Object valeur) {
        try {
            return json.writeValueAsString(valeur);
        } catch (JsonProcessingException e) {
            throw new IllegalStateException("Sérialisation JSON de l'alerte impossible", e);
        }
    }
}
