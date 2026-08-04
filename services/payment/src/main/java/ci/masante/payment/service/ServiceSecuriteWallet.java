package ci.masante.payment.service;

import ci.masante.payment.domain.model.WalletLimites;
import ci.masante.payment.domain.model.WalletPin;
import ci.masante.payment.domain.wallet.ChallengeRequisException;
import ci.masante.payment.domain.wallet.OtpInvalideException;
import ci.masante.payment.domain.wallet.OtpRequisException;
import ci.masante.payment.domain.wallet.PinInvalideException;
import ci.masante.payment.domain.wallet.PinVerrouilleException;
import ci.masante.payment.domain.wallet.ReglesSecuriteWallet;
import ci.masante.payment.repository.WalletEntryRepository;
import ci.masante.payment.repository.WalletLimitesRepository;
import ci.masante.payment.repository.WalletPinRepository;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Lazy;
import org.springframework.data.redis.core.StringRedisTemplate;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Propagation;
import org.springframework.transaction.annotation.Transactional;

import java.security.SecureRandom;
import java.time.Duration;
import java.time.Instant;
import java.time.LocalDate;
import java.time.YearMonth;
import java.time.ZoneOffset;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

/**
 * Sécurité transactionnelle du Wallet (CDC_06 §6.4) — <b>frontière</b> : PIN, OTP et limites sont
 * vérifiés ici, côté backend seul ; le front ne décide jamais. PIN haché BCrypt (jamais en clair) ;
 * OTP à usage unique en Redis (TTL) ; consommations jour/mois dérivées du grand livre.
 *
 * <p><b>Paiement SIMULÉ</b> : l'OTP n'est envoyé par aucun SMS réel (FT5). Il est renvoyé en clair
 * par {@code genererOtp} pour permettre les tests ; le canal SMS est « prêt à activer ».</p>
 */
@Service
public class ServiceSecuriteWallet {

    private static final String PREFIXE_OTP = "wallet:otp:";
    private static final SecureRandom ALEA = new SecureRandom();

    private final WalletPinRepository pins;
    private final WalletLimitesRepository limites;
    private final WalletEntryRepository entries;
    private final StringRedisTemplate redis;
    private final ServiceAudit audit;
    private final ServiceSecuriteWallet self;
    private final PasswordEncoder encodeur = new BCryptPasswordEncoder();

    private final int pinMaxEssais;
    private final int pinVerrouMinutes;
    private final long otpSeuil;
    private final long otpTtlSeconds;
    private final long limiteOperation;
    private final long limiteJour;
    private final long limiteMois;

    public ServiceSecuriteWallet(
            WalletPinRepository pins, WalletLimitesRepository limites, WalletEntryRepository entries,
            StringRedisTemplate redis, ServiceAudit audit, @Lazy ServiceSecuriteWallet self,
            @Value("${masante.payment.wallet.pin.max-essais:3}") int pinMaxEssais,
            @Value("${masante.payment.wallet.pin.verrou-minutes:15}") int pinVerrouMinutes,
            @Value("${masante.payment.wallet.otp.seuil:100000}") long otpSeuil,
            @Value("${masante.payment.wallet.otp.ttl-seconds:300}") long otpTtlSeconds,
            @Value("${masante.payment.wallet.limites.operation:500000}") long limiteOperation,
            @Value("${masante.payment.wallet.limites.jour:1000000}") long limiteJour,
            @Value("${masante.payment.wallet.limites.mois:5000000}") long limiteMois) {
        this.pins = pins;
        this.limites = limites;
        this.entries = entries;
        this.redis = redis;
        this.audit = audit;
        this.self = self;
        this.pinMaxEssais = pinMaxEssais;
        this.pinVerrouMinutes = pinVerrouMinutes;
        this.otpSeuil = otpSeuil;
        this.otpTtlSeconds = otpTtlSeconds;
        this.limiteOperation = limiteOperation;
        this.limiteJour = limiteJour;
        this.limiteMois = limiteMois;
    }

    // --- gestion du PIN -----------------------------------------------------------------------

    /** Définit (première fois) ou change le PIN. Le changement exige l'ancien PIN. */
    @Transactional
    public void definirPin(UUID walletId, String nouveauPin, String ancienPin) {
        ReglesSecuriteWallet.verifierFormatPin(nouveauPin);
        Optional<WalletPin> existant = pins.findById(walletId);
        if (existant.isPresent()) {
            WalletPin p = existant.get();
            if (ancienPin == null || !encodeur.matches(ancienPin, p.getHash())) {
                throw new PinInvalideException("Ancien PIN incorrect.");
            }
            p.changerHash(encodeur.encode(nouveauPin));
            pins.save(p);
        } else {
            pins.save(new WalletPin(walletId, encodeur.encode(nouveauPin)));
        }
        audit.enregistrer("WalletPinDefini", "wallet", walletId.toString(), Map.of());
    }

    /**
     * Vérifie le PIN. Le résultat d'un échec (compteur + verrou) est écrit par une méthode PROPRE
     * ({@code REQUIRES_NEW}) qui <b>retourne normalement</b> AVANT que l'exception ne soit levée :
     * sinon le {@code throw} annulerait la transaction et l'incrément serait perdu (le verrou ne
     * s'accumulerait jamais). Méthode non transactionnelle : elle orchestre, elle n'écrit pas.
     */
    public void verifierPin(UUID walletId, String pin) {
        if (pin == null || pin.isBlank()) {
            throw new PinInvalideException("PIN requis pour cette opération.");
        }
        WalletPin p = pins.findById(walletId)
                .orElseThrow(() -> new PinInvalideException("Aucun PIN défini pour ce portefeuille."));
        if (p.estVerrouille(Instant.now())) {
            throw new PinVerrouilleException(p.getVerrouJusquA());
        }
        if (encodeur.matches(pin, p.getHash())) {
            self.marquerSuccesPin(walletId);
            return;
        }
        EtatVerrou etat = self.marquerEchecPin(walletId);
        if (etat.verrouille()) {
            throw new PinVerrouilleException(etat.verrouJusquA());
        }
        throw new PinInvalideException("PIN incorrect.");
    }

    /** Succès : réinitialise le compteur d'échecs (commit indépendant). */
    @Transactional(propagation = Propagation.REQUIRES_NEW)
    public void marquerSuccesPin(UUID walletId) {
        pins.findById(walletId).ifPresent(p -> {
            if (p.getEssaisEchoues() != 0) {
                p.reinitialiser();
                pins.save(p);
            }
        });
    }

    /**
     * Échec : incrémente le compteur, pose le verrou au seuil, audite — le tout <b>commité</b> dans
     * une transaction propre puis renvoyé, de sorte que le compteur survit à l'échec de l'opération.
     */
    @Transactional(propagation = Propagation.REQUIRES_NEW)
    public EtatVerrou marquerEchecPin(UUID walletId) {
        WalletPin p = pins.findById(walletId)
                .orElseThrow(() -> new PinInvalideException("Aucun PIN défini pour ce portefeuille."));
        Instant maintenant = Instant.now();
        p.enregistrerEchec(pinMaxEssais, pinVerrouMinutes, maintenant);
        pins.save(p);
        boolean verrouille = p.estVerrouille(maintenant);
        audit.enregistrer("WalletPinEchec", "wallet", walletId.toString(),
                Map.of("essais", p.getEssaisEchoues(), "verrouille", verrouille));
        return new EtatVerrou(verrouille, p.getVerrouJusquA());
    }

    // --- OTP (à usage unique, Redis) ----------------------------------------------------------

    /**
     * Génère un OTP pour une opération de {@code montant} si le seuil l'exige. Le code est renvoyé
     * (paiement SIMULÉ, FT5 — SMS « prêt à activer ») ; en base/Redis seul son haché est conservé.
     */
    public ResultatOtp genererOtp(UUID walletId, long montant) {
        // Un code est TOUJOURS délivré : le montant peut ne pas l'exiger, mais le palier CHALLENGE de
        // la détection de fraude en réclame un quel que soit le montant. `requis` reste indicatif.
        boolean requis = ReglesSecuriteWallet.otpRequis(montant, otpSeuil);
        String code = String.format("%06d", ALEA.nextInt(1_000_000));
        redis.opsForValue().set(PREFIXE_OTP + walletId, encodeur.encode(code), Duration.ofSeconds(otpTtlSeconds));
        audit.enregistrer("WalletOtpGenere", "wallet", walletId.toString(),
                Map.of("ttl_s", otpTtlSeconds, "requisParMontant", requis));
        return new ResultatOtp(requis, code, Instant.now().plusSeconds(otpTtlSeconds));
    }

    private void verifierOtpSiRequis(UUID walletId, long montant, String otp) {
        if (!ReglesSecuriteWallet.otpRequis(montant, otpSeuil)) {
            return;
        }
        if (otp == null || otp.isBlank()) {
            throw new OtpRequisException(otpSeuil);
        }
        String hash = redis.opsForValue().get(PREFIXE_OTP + walletId);
        if (hash == null) {
            throw new OtpInvalideException("OTP expiré ou absent — régénérez-en un.");
        }
        if (!encodeur.matches(otp, hash)) {
            throw new OtpInvalideException("OTP incorrect.");
        }
        redis.delete(PREFIXE_OTP + walletId); // usage unique
    }

    /** true si le montant à lui seul impose déjà l'OTP (donc déjà vérifié par {@code autoriserOperation}). */
    public boolean otpExigeParMontant(long montant) {
        return ReglesSecuriteWallet.otpRequis(montant, otpSeuil);
    }

    /**
     * Exige un OTP valide <b>indépendamment du montant</b> (palier CHALLENGE de la détection de
     * fraude). Message générique via {@link ChallengeRequisException} : aucun détail de risque ne fuit.
     */
    public void exigerOtp(UUID walletId, String otp) {
        if (otp == null || otp.isBlank()) {
            throw new ChallengeRequisException();
        }
        String hash = redis.opsForValue().get(PREFIXE_OTP + walletId);
        if (hash == null || !encodeur.matches(otp, hash)) {
            throw new ChallengeRequisException();
        }
        redis.delete(PREFIXE_OTP + walletId); // usage unique
    }

    // --- limites ------------------------------------------------------------------------------

    /** Lit les limites effectives (surcharge par wallet, sinon défauts de configuration). */
    @Transactional(readOnly = true)
    public LimitesEffectives limitesEffectives(UUID walletId) {
        WalletLimites l = limites.findById(walletId).orElse(null);
        return new LimitesEffectives(
                valeur(l == null ? null : l.getPlafondOperation(), limiteOperation),
                valeur(l == null ? null : l.getPlafondJour(), limiteJour),
                valeur(l == null ? null : l.getPlafondMois(), limiteMois));
    }

    @Transactional
    public LimitesEffectives definirLimites(UUID walletId, Long operation, Long jour, Long mois) {
        WalletLimites l = limites.findById(walletId).orElseGet(() -> new WalletLimites(walletId));
        l.definir(operation, jour, mois);
        limites.save(l);
        audit.enregistrer("WalletLimitesDefinies", "wallet", walletId.toString(),
                mapLimites(operation, jour, mois));
        return limitesEffectives(walletId);
    }

    private void controlerLimites(UUID walletId, long montant) {
        LimitesEffectives eff = limitesEffectives(walletId);
        long dejaJour = entries.debitsDepuis(walletId, debutJour());
        long dejaMois = entries.debitsDepuis(walletId, debutMois());
        ReglesSecuriteWallet.verifierLimiteOperation(montant, eff.operation());
        ReglesSecuriteWallet.verifierLimiteJournaliere(dejaJour, montant, eff.jour());
        ReglesSecuriteWallet.verifierLimiteMensuelle(dejaMois, montant, eff.mois());
    }

    // --- orchestration : appelée par ServiceWallet avant toute opération sortante --------------

    /** PIN → limites → OTP (si seuil). Toute vérification échoue = l'opération n'a pas lieu. */
    public void autoriserOperation(UUID walletId, long montant, String pin, String otp) {
        self.verifierPin(walletId, pin); // via proxy → REQUIRES_NEW (le compteur d'échecs persiste)
        controlerLimites(walletId, montant);
        verifierOtpSiRequis(walletId, montant, otp);
    }

    // --- utilitaires --------------------------------------------------------------------------

    private static long valeur(Long surcharge, long defaut) {
        return surcharge != null ? surcharge : defaut;
    }

    private static Instant debutJour() {
        return LocalDate.now(ZoneOffset.UTC).atStartOfDay(ZoneOffset.UTC).toInstant();
    }

    private static Instant debutMois() {
        return YearMonth.now(ZoneOffset.UTC).atDay(1).atStartOfDay(ZoneOffset.UTC).toInstant();
    }

    private static Map<String, Object> mapLimites(Long operation, Long jour, Long mois) {
        return Map.of(
                "operation", operation == null ? "défaut" : operation,
                "jour", jour == null ? "défaut" : jour,
                "mois", mois == null ? "défaut" : mois);
    }

    /** Limites effectives d'un wallet (FCFA ; {@code <= 0} = illimité). */
    public record LimitesEffectives(long operation, long jour, long mois) {
    }

    /** Résultat de génération d'OTP. {@code code} n'est renseigné qu'en mode simulé (FT5). */
    public record ResultatOtp(boolean requis, String code, Instant expireLe) {
    }

    /** État du verrou après un échec de PIN (renvoyé par la transaction propre). */
    private record EtatVerrou(boolean verrouille, Instant verrouJusquA) {
    }
}
