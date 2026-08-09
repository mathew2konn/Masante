package ci.masante.payment.service;

import ci.masante.payment.domain.model.AlerteFraudeIa;
import ci.masante.payment.domain.model.NiveauFraudeIa;
import ci.masante.payment.domain.notification.TypeNotification;
import ci.masante.payment.repository.AlerteFraudeIaRepository;
import ci.masante.payment.repository.RequetesSignauxFraude;
import ci.masante.payment.service.ClientFraudeDetection.ResultatFraudeVue;
import ci.masante.payment.web.dto.SignauxFactureReponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Lazy;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.time.temporal.ChronoUnit;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.function.Function;
import java.util.stream.Collectors;

/**
 * Routage des alertes de fraude IA (CDC_05, B1). Le paiement <b>orchestre</b> : sélectionne les factures
 * d'une fenêtre → extrait leurs signaux (réutilise {@link ServiceSignauxFraude}) → demande un score au
 * fraud-detection-service ({@link ClientFraudeDetection}) → <b>persiste les alertes ≥ SUSPECT</b> et
 * <b>émet une notification Outbox</b> vers le contrôleur plateforme {@code ADMIN_FINANCE} (P5.4c).
 *
 * <p><b>DÉTECTION SEULE</b> (ADR-017) : on notifie un humain, on ne gèle/corrige jamais. Le
 * fraud-detection-service reste passif (il note, il n'envoie rien lui-même). <b>Destinataire figé</b> :
 * contrôleur anti-fraude/conformité indépendant — jamais le directeur de la structure signalée.</p>
 *
 * <p><b>Idempotent</b> : une alerte au plus par {@code (facture, journée)} ; rejouer un scan met à jour
 * le verdict et <b>ne ré-émet pas</b> de notification (pas de spam). <b>Dégradation honnête</b> : fraude
 * injoignable → {@link FraudeInjoignableException} (502), aucune alerte inventée, aucun état partiel
 * (rien n'est persisté avant d'avoir le score). L'appel HTTP est <b>hors transaction</b> ; la persistance
 * alerte + Outbox se fait dans une seule transaction (via {@code self}, sinon l'auto-invocation
 * contournerait {@code @Transactional}).</p>
 */
@Service
public class ServiceRoutageFraude {

    private static final Logger log = LoggerFactory.getLogger(ServiceRoutageFraude.class);

    private final RequetesSignauxFraude requetes;
    private final ServiceSignauxFraude signaux;
    private final ClientFraudeDetection fraude;
    private final AlerteFraudeIaRepository alertes;
    private final ServiceNotifications notifications;
    private final ObjectMapper json;
    private final int fenetreJours;
    private final int limite;
    private final String destinataireRef;
    private final ServiceRoutageFraude self;

    public ServiceRoutageFraude(
            RequetesSignauxFraude requetes, ServiceSignauxFraude signaux, ClientFraudeDetection fraude,
            AlerteFraudeIaRepository alertes, ServiceNotifications notifications, ObjectMapper json,
            @Value("${masante.payment.fraude.fenetre-jours:1}") int fenetreJours,
            @Value("${masante.payment.fraude.limite-factures:500}") int limite,
            @Value("${masante.payment.fraude.destinataire-ref:CTRL-FRAUDE-PLATEFORME}") String destinataireRef,
            @Lazy ServiceRoutageFraude self) {
        this.requetes = requetes;
        this.signaux = signaux;
        this.fraude = fraude;
        this.alertes = alertes;
        this.notifications = notifications;
        this.json = json;
        this.fenetreJours = fenetreJours;
        this.limite = limite;
        this.destinataireRef = destinataireRef;
        this.self = self;
    }

    /**
     * Exécute le routage d'une journée : factures créées dans [T − fenêtre, T] (T = fin de la journée UTC).
     * L'appel au fraud-detection-service est fait HORS transaction ; la persistance suit.
     */
    public RapportRoutage executer(LocalDate journee) {
        Instant cutOff = journee.plusDays(1).atStartOfDay(ZoneOffset.UTC).toInstant();
        Instant depuis = cutOff.minus(fenetreJours, ChronoUnit.DAYS);

        List<String> numeros = requetes.numerosFacturesEntre(depuis, cutOff, limite);
        if (numeros.isEmpty()) {
            return new RapportRoutage(journee, 0, 0, 0, 0);
        }
        List<SignauxFactureReponse> vecteurs = signaux.extraireLot(numeros, cutOff);
        List<ResultatFraudeVue> vues = fraude.scorer(vecteurs); // HTTP hors transaction (peut lever 502)

        Map<String, SignauxFactureReponse> parReference = vecteurs.stream()
                .collect(Collectors.toMap(SignauxFactureReponse::reference, Function.identity(),
                        (a, b) -> a, LinkedHashMap::new));

        return self.persister(journee, cutOff, vues, parReference);
    }

    /** Persiste les alertes ≥ SUSPECT et émet les notifications (même transaction = Outbox fiable). */
    @Transactional
    public RapportRoutage persister(LocalDate journee, Instant cutOff, List<ResultatFraudeVue> vues,
                                    Map<String, SignauxFactureReponse> parReference) {
        int suspectes = 0;
        int nouvelles = 0;
        int notifiees = 0;
        for (ResultatFraudeVue vue : vues) {
            NiveauFraudeIa niveau = niveauDe(vue.niveau());
            if (niveau != NiveauFraudeIa.SUSPECT && niveau != NiveauFraudeIa.TRES_SUSPECT) {
                continue; // NORMAL n'est jamais persisté ni notifié
            }
            suspectes++;
            SignauxFactureReponse sig = parReference.get(vue.reference());
            String signauxJson = serialiser(sig);

            Optional<AlerteFraudeIa> existante = alertes.findByFactureRefAndDateRapport(vue.reference(), journee);
            if (existante.isPresent()) {
                existante.get().reevaluer(niveau, vue.score(), vue.mode(),
                        vue.reglesJson(), vue.facteursJson(), signauxJson, cutOff);
                alertes.save(existante.get());
                continue; // déjà notifiée : pas de nouvelle notification (anti-spam)
            }

            AlerteFraudeIa alerte = alertes.save(new AlerteFraudeIa(
                    vue.reference(), sig != null ? sig.etablissementRef() : "?", null, journee,
                    niveau, vue.score(), vue.mode(), vue.reglesJson(), vue.facteursJson(), signauxJson, cutOff));
            nouvelles++;

            notifications.emettre(TypeNotification.FRAUDE_SUSPECTEE, "ia_fraude_alerte", alerte.getId(),
                    destinataireRef, chargeNotification(alerte));
            alerte.marquerNotifiee();
            alertes.save(alerte);
            notifiees++;
        }
        log.info("Routage fraude — journée {} : {} évaluée(s), {} suspecte(s), {} nouvelle(s), {} notifiée(s)",
                journee, vues.size(), suspectes, nouvelles, notifiees);
        return new RapportRoutage(journee, vues.size(), suspectes, nouvelles, notifiees);
    }

    private static NiveauFraudeIa niveauDe(String valeur) {
        try {
            return NiveauFraudeIa.valueOf(valeur);
        } catch (IllegalArgumentException | NullPointerException e) {
            return NiveauFraudeIa.NORMAL; // valeur inattendue → ignorée (ni alerte ni notif)
        }
    }

    private Map<String, Object> chargeNotification(AlerteFraudeIa a) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("alerteId", a.getId().toString());
        m.put("factureRef", a.getFactureRef());
        m.put("etablissementRef", a.getEtablissementRef());
        m.put("niveau", a.getNiveau().name());
        m.put("score", a.getScore());
        m.put("message", "Suspicion de fraude sur la facture " + a.getFactureRef()
                + " (" + a.getNiveau().name() + ", score " + a.getScore() + "). Revue requise.");
        return m;
    }

    private String serialiser(SignauxFactureReponse sig) {
        if (sig == null) {
            return "{}";
        }
        try {
            return json.writeValueAsString(sig);
        } catch (com.fasterxml.jackson.core.JsonProcessingException e) {
            throw new IllegalStateException("Sérialisation du snapshot de signaux impossible", e);
        }
    }

    /** Résumé d'un run de routage (pour l'endpoint manuel et le log du planificateur). */
    public record RapportRoutage(LocalDate journee, int nbEvaluees, int nbSuspectes, int nbNouvelles,
                                 int nbNotifiees) {
    }
}
