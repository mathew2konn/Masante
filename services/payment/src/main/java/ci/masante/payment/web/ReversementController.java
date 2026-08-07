package ci.masante.payment.web;

import ci.masante.payment.domain.model.EcritureReversement;
import ci.masante.payment.domain.model.ReversementReleve;
import ci.masante.payment.domain.model.TypeDestination;
import ci.masante.payment.service.ServiceCommissionConfig;
import ci.masante.payment.service.ServiceDestinationReversement;
import ci.masante.payment.service.ServicePrincipal;
import ci.masante.payment.service.ServicePrincipal.PrincipalAuthentifie;
import ci.masante.payment.service.ServiceReversement;
import ci.masante.payment.web.dto.AnnulerReversementRequete;
import ci.masante.payment.web.dto.CalculerReversementRequete;
import ci.masante.payment.web.dto.CommissionConfigReponse;
import ci.masante.payment.web.dto.DestinationReponse;
import ci.masante.payment.web.dto.EcritureReponse;
import ci.masante.payment.web.dto.LigneGrandLivreReponse;
import ci.masante.payment.web.dto.LigneReversementReponse;
import ci.masante.payment.web.dto.OuvrirCommissionRequete;
import ci.masante.payment.web.dto.OuvrirDestinationRequete;
import ci.masante.payment.web.dto.RejeterReversementRequete;
import ci.masante.payment.web.dto.ReversementReleveDetailReponse;
import ci.masante.payment.web.dto.ReversementReleveReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.UUID;

/**
 * Reversements aux établissements (CDC_06 §11) — P5.5b-1 : calcul (P5.5a), registre de destinations
 * chiffré, approbation à <b>quatre-yeux</b> avec écriture de constatation, rejet. Le versement effectif =
 * P5.5b-2. Deux niveaux d'authentification : les actes non sensibles (calcul, annulation, lecture) portent
 * l'identité via {@code X-Acteur-Id} (posé par la passerelle) ; les actes <b>sensibles</b> (destinations,
 * approbation, rejet, taux) exigent un <b>principal signé</b> vérifié en service ({@code X-Principal} +
 * {@code X-Principal-Sig}) et le rôle {@code ADMIN_FINANCE} — jamais un rôle déclaré en clair par le client.
 */
@RestController
@RequestMapping("/api/v1")
@Validated
@Tag(name = "Reversements", description = "Sommes dues, destinations, approbation à quatre-yeux (CDC_06 §11)")
public class ReversementController {

    private static final String ROLE_ADMIN_FINANCE = "ADMIN_FINANCE";

    private final ServiceReversement reversement;
    private final ServiceCommissionConfig commissionConfig;
    private final ServiceDestinationReversement destinations;
    private final ServicePrincipal principal;

    public ReversementController(ServiceReversement reversement, ServiceCommissionConfig commissionConfig,
                                 ServiceDestinationReversement destinations, ServicePrincipal principal) {
        this.reversement = reversement;
        this.commissionConfig = commissionConfig;
        this.destinations = destinations;
        this.principal = principal;
    }

    // --- relevés : calcul / annulation / lecture (X-Acteur-Id) --------------------------------

    @PostMapping("/settlements/run")
    @Operation(summary = "Calculer le relevé de reversement d'un établissement (acteur via X-Acteur-Id)")
    public ResponseEntity<ReversementReleveReponse> calculer(
            @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @Valid @RequestBody CalculerReversementRequete r) {
        ReversementReleve releve = reversement.calculerReleve(
                r.etablissementRef(), r.periodeDebut(), r.periodeFin(), r.cutOff(), acteur);
        return ResponseEntity.status(HttpStatus.CREATED).body(ReversementReleveReponse.de(releve));
    }

    @PostMapping("/settlements/{id}/cancel")
    @Operation(summary = "Annuler un relevé (libère la période ; extourne la constatation si approuvé)")
    public ReversementReleveReponse annuler(
            @PathVariable UUID id, @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @Valid @RequestBody AnnulerReversementRequete r) {
        return ReversementReleveReponse.de(reversement.annuler(id, r.motif(), acteur));
    }

    @GetMapping("/settlements/{id}")
    @Operation(summary = "Détail d'un relevé + ses lignes")
    public ReversementReleveDetailReponse detail(@PathVariable UUID id) {
        ReversementReleve releve = reversement.trouver(id);
        List<LigneReversementReponse> lignes = reversement.lignesDe(id).stream()
                .map(LigneReversementReponse::de).toList();
        return new ReversementReleveDetailReponse(ReversementReleveReponse.de(releve), lignes);
    }

    @GetMapping("/settlements")
    @Operation(summary = "Lister les relevés d'un établissement (exercice optionnel)")
    public List<ReversementReleveReponse> lister(
            @RequestParam("etablissement") @NotBlank String etablissement,
            @RequestParam(value = "exercice", required = false) Integer exercice) {
        return reversement.lister(etablissement, exercice).stream().map(ReversementReleveReponse::de).toList();
    }

    @GetMapping("/settlements/{id}/ledger")
    @Operation(summary = "Grand livre d'un relevé (écritures + jambes)")
    public List<EcritureReponse> ledger(@PathVariable UUID id) {
        return reversement.ecrituresDe(id).stream()
                .map(e -> EcritureReponse.de(e, reversement.jambesDe(e.getEcritureId()).stream()
                        .map(LigneGrandLivreReponse::de).toList()))
                .toList();
    }

    // --- actes sensibles : principal signé + ADMIN_FINANCE ------------------------------------

    @PostMapping("/settlements/{id}/approve")
    @Operation(summary = "Approuver un relevé à quatre-yeux (principal signé ADMIN_FINANCE ; constatation comptable)")
    public ReversementReleveReponse approuver(
            @PathVariable UUID id,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            HttpServletRequest requete) {
        PrincipalAuthentifie p = adminFinance(xPrincipal, xSig, requete);
        return ReversementReleveReponse.de(reversement.approuver(id, p.sub()));
    }

    @PostMapping("/settlements/{id}/reject")
    @Operation(summary = "Rejeter un relevé (principal signé ADMIN_FINANCE ; libère la période)")
    public ReversementReleveReponse rejeter(
            @PathVariable UUID id,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @Valid @RequestBody RejeterReversementRequete r,
            HttpServletRequest requete) {
        PrincipalAuthentifie p = adminFinance(xPrincipal, xSig, requete);
        return ReversementReleveReponse.de(reversement.rejeter(id, p.sub(), r.motif()));
    }

    @PostMapping("/settlements/destinations")
    @Operation(summary = "Ouvrir une destination de versement (principal signé ADMIN_FINANCE ; chiffrée)")
    public ResponseEntity<DestinationReponse> ouvrirDestination(
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @Valid @RequestBody OuvrirDestinationRequete r,
            HttpServletRequest requete) {
        PrincipalAuthentifie p = adminFinance(xPrincipal, xSig, requete);
        return ResponseEntity.status(HttpStatus.CREATED).body(DestinationReponse.de(
                destinations.ouvrir(r.etablissementRef(), r.type(), r.reference(), r.motif(), p.sub())));
    }

    @GetMapping("/settlements/destinations")
    @Operation(summary = "Historique des destinations d'un établissement (ref_chiffree jamais exposée)")
    public List<DestinationReponse> listerDestinations(
            @RequestParam("etablissement") @NotBlank String etablissement) {
        return destinations.historique(etablissement).stream().map(DestinationReponse::de).toList();
    }

    @PostMapping("/settlements/commission-config")
    @Operation(summary = "Ouvrir un taux de commission (principal signé ADMIN_FINANCE ; clôture le précédent)")
    public ResponseEntity<CommissionConfigReponse> ouvrirTaux(
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            @Valid @RequestBody OuvrirCommissionRequete r,
            HttpServletRequest requete) {
        PrincipalAuthentifie p = adminFinance(xPrincipal, xSig, requete);
        return ResponseEntity.status(HttpStatus.CREATED).body(CommissionConfigReponse.de(
                commissionConfig.ouvrir(r.etablissementRef(), r.tauxBps(), r.motif(), p.sub())));
    }

    @GetMapping("/settlements/commission-config")
    @Operation(summary = "Taux de commission ouvert d'un périmètre (etablissement optionnel = défaut plateforme)")
    public ResponseEntity<CommissionConfigReponse> courantTaux(
            @RequestParam(value = "etablissement", required = false) String etablissement) {
        return commissionConfig.courantPour(etablissement)
                .map(c -> ResponseEntity.ok(CommissionConfigReponse.de(c)))
                .orElseGet(() -> ResponseEntity.notFound().build());
    }

    /** Vérifie le principal signé (lié à méthode+chemin, anti-rejeu) et exige le rôle ADMIN_FINANCE. */
    private PrincipalAuthentifie adminFinance(String xPrincipal, String xSig, HttpServletRequest requete) {
        PrincipalAuthentifie p = principal.verifier(xPrincipal, xSig, requete.getMethod(), requete.getRequestURI());
        principal.exigerRole(p, ROLE_ADMIN_FINANCE);
        return p;
    }
}
