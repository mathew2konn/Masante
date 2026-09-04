package ci.masante.payment.web;

import ci.masante.payment.domain.model.GeniusPayTransaction;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.service.ServiceGeniusPay;
import ci.masante.payment.service.ServiceMarchandGeniusPay;
import ci.masante.payment.service.ServicePrincipal;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.time.Instant;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Endpoints internes du paiement GeniusPay — appelés par le backend Laravel, jamais exposés au public.
 *
 * <p><b>L'authentification n'est pas réinventée</b> : c'est le principal signé
 * ({@link ServicePrincipal}), déjà en place depuis P5.5b-1 et partagé avec Laravel et le portail. Le
 * mécanisme lie la signature à la méthode et au chemin, refuse un nonce déjà vu, et compare en temps
 * constant. Comme ailleurs dans ce service, la vérification est <b>explicite dans le contrôleur</b>
 * — il n'existe pas de filtre global, et c'est ce qui rend l'exemption du webhook non nécessaire.</p>
 */
@RestController
@RequestMapping("/api/v1/interne/geniuspay")
@Tag(name = "GeniusPay — Interne", description = "Initiation et consultation, réservées au backend")
public class GeniusPayController {

    private static final String ROLE_REQUIS = "SYSTEME";

    private final ServiceGeniusPay service;
    private final ServiceMarchandGeniusPay marchands;
    private final ServicePrincipal principal;

    public GeniusPayController(ServiceGeniusPay service, ServiceMarchandGeniusPay marchands,
                               ServicePrincipal principal) {
        this.service = service;
        this.marchands = marchands;
        this.principal = principal;
    }

    @PostMapping("/paiements")
    @Operation(summary = "Ouvrir un checkout GeniusPay pour une facture")
    public ResponseEntity<Map<String, Object>> initier(
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @RequestHeader("Idempotency-Key") @NotBlank String cleIdempotence,
            @Valid @RequestBody DemandeCheckoutRequest corps,
            HttpServletRequest requete) {
        exiger(xPrincipal, xSig, requete);
        var resultat = service.initierPourFacture(new ServiceGeniusPay.DemandeCheckout(
                corps.factureId(), corps.montant(), corps.devise(), corps.etablissementRef(),
                corps.patientRef(), corps.correlationId(),
                corps.objet() == null ? ObjetPaiement.FACTURE : corps.objet()), cleIdempotence);
        return ResponseEntity.ok(vue(resultat.transaction(), resultat.rejoue(), resultat.avertissements()));
    }

    @GetMapping("/paiements/{referenceInterne}")
    @Operation(summary = "Consulter une transaction par sa référence interne")
    public ResponseEntity<Map<String, Object>> consulter(
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @PathVariable String referenceInterne,
            HttpServletRequest requete) {
        exiger(xPrincipal, xSig, requete);
        var resultat = service.parReferenceInterne(referenceInterne);
        return ResponseEntity.ok(vue(resultat.transaction(), false, resultat.avertissements()));
    }

    /**
     * Enregistre les identifiants marchands d'un établissement et renvoie <b>l'URL de rappel</b> à
     * déclarer chez le prestataire. Le slug qu'elle porte est généré ici, aléatoirement.
     *
     * <p>Les clés arrivent en clair dans ce corps et n'en ressortent jamais : elles sont chiffrées
     * avant d'être écrites, et la réponse ne les cite pas.</p>
     */
    @PostMapping("/marchands")
    @Operation(summary = "Enregistrer les identifiants marchands d'un établissement")
    public ResponseEntity<Map<String, Object>> enregistrerMarchand(
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @Valid @RequestBody DemandeMarchandRequest corps,
            HttpServletRequest requete) {
        exiger(xPrincipal, xSig, requete);
        var marchand = marchands.enregistrer(corps.etablissementRef(), corps.clePublique(), corps.cleSecrete());
        Map<String, Object> vue = new LinkedHashMap<>();
        vue.put("etablissementRef", marchand.getEtablissementRef());
        vue.put("cheminRappel", "/api/v1/paiement-webhooks/geniuspay/" + marchand.getSlug());
        vue.put("secretWebhookEnregistre", marchand.aUnSecretWebhook());
        return ResponseEntity.ok(vue);
    }

    /**
     * L'établissement peut-il encaisser en ligne ? (B4, S7, ADR-056). Répond « configuré : oui/non »,
     * jamais les clés — le contrôleur ne les cite déjà nulle part, et {@code estConfigure} ne les lit
     * même pas.
     *
     * <p><b>C'est la réponse à cette question qui vit ici, et nulle part ailleurs</b> : Laravel
     * l'interroge et met la réponse en cache quelques minutes (un cache, pas une copie — il se périme
     * seul et n'est jamais la source). Recopier la liste des marchands côté Laravel produirait deux
     * réponses possibles à la même question, divergeant le jour où l'une est mise à jour sans
     * l'autre.</p>
     */
    @GetMapping("/marchands/{etablissementRef}")
    @Operation(summary = "L'établissement est-il configuré pour encaisser en ligne ?")
    public ResponseEntity<Map<String, Object>> marchandConfigure(
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @PathVariable String etablissementRef,
            HttpServletRequest requete) {
        exiger(xPrincipal, xSig, requete);
        Map<String, Object> vue = new LinkedHashMap<>();
        vue.put("etablissementRef", etablissementRef);
        vue.put("configure", marchands.estConfigure(etablissementRef));
        return ResponseEntity.ok(vue);
    }

    /**
     * Dépose le secret webhook d'un établissement. Il n'est renvoyé par le prestataire qu'à la
     * création du webhook, une seule fois : c'est pour cela qu'il entre par un appel dédié plutôt que
     * d'être supposé connu.
     */
    @PostMapping("/marchands/{etablissementRef}/secret-webhook")
    @Operation(summary = "Déposer le secret webhook (whsec_) d'un établissement")
    public ResponseEntity<Map<String, Object>> deposerSecret(
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @PathVariable String etablissementRef,
            @Valid @RequestBody DemandeSecretRequest corps,
            HttpServletRequest requete) {
        exiger(xPrincipal, xSig, requete);
        marchands.deposerSecretWebhook(etablissementRef, corps.secretWebhook());
        return ResponseEntity.ok(Map.of("etablissementRef", etablissementRef, "secretWebhookEnregistre", true));
    }

    private void exiger(String xPrincipal, String xSig, HttpServletRequest requete) {
        var p = principal.verifier(xPrincipal, xSig, requete.getMethod(), requete.getRequestURI());
        principal.exigerRole(p, ROLE_REQUIS);
    }

    /** Vue de sortie : ni clé, ni secret, ni slug. Le slug est un sélecteur de secret, pas une donnée. */
    private static Map<String, Object> vue(GeniusPayTransaction t, boolean rejoue, List<String> avertissements) {
        Map<String, Object> vue = new LinkedHashMap<>();
        vue.put("referenceInterne", t.getReferenceInterne());
        vue.put("referencePasserelle", t.getReferencePasserelle());
        vue.put("statut", t.getStatutGeniusPay().name());
        vue.put("statutPartage", t.getStatutGeniusPay().versStatutPartage().name());
        vue.put("checkoutUrl", t.getCheckoutUrl());
        vue.put("expireLe", t.getExpireLe() == null ? null : t.getExpireLe().toString());
        vue.put("fraisPasserelle", t.getFraisPasserelle());
        vue.put("montantNet", t.getMontantNet());
        vue.put("canal", t.getCanal());
        vue.put("rejoue", rejoue);
        vue.put("avertissements", avertissements);
        vue.put("consulteLe", Instant.now().toString());
        return vue;
    }

    public record DemandeCheckoutRequest(
            @NotNull UUID factureId,
            @Positive long montant,
            String devise,
            @NotBlank String etablissementRef,
            String patientRef,
            String correlationId,
            ObjetPaiement objet
    ) {
    }

    public record DemandeMarchandRequest(
            @NotBlank String etablissementRef,
            @NotBlank String clePublique,
            @NotBlank String cleSecrete
    ) {
    }

    public record DemandeSecretRequest(@NotBlank String secretWebhook) {
    }
}
