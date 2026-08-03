package ci.masante.payment.service;

import ci.masante.payment.domain.gateway.RegistrePasserelles;
import ci.masante.payment.domain.gateway.RequetePaiement;
import ci.masante.payment.domain.gateway.RequeteRemboursement;
import ci.masante.payment.domain.gateway.ResultatPaiement;
import ci.masante.payment.domain.gateway.ResultatRemboursement;
import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.TransitionPaiement;
import ci.masante.payment.domain.statemachine.MachineEtatsPaiement;
import ci.masante.payment.repository.PaiementRepository;
import ci.masante.payment.repository.TransitionPaiementRepository;
import org.springframework.context.annotation.Lazy;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.HashMap;
import java.util.Map;
import java.util.UUID;

/**
 * Orchestration du cycle de vie d'un paiement (CDC_06 §4).
 *
 * <p>Garanties : <b>idempotence</b> (verrou Redis + unicité PG), <b>machine à états stricte</b>
 * (toute transition passe par {@link MachineEtatsPaiement}), <b>audit immuable</b> (chaque étape
 * consignée dans la même transaction → cohérence forte, §10.3). PAIEMENT SIMULÉ via la passerelle.</p>
 */
@Service
public class ServicePaiement {

    private final PaiementRepository paiements;
    private final TransitionPaiementRepository transitions;
    private final RegistrePasserelles passerelles;
    private final ServiceIdempotence idempotence;
    private final ServiceAudit audit;
    private final ServiceFacturation facturation;
    private final ServicePaiement self;

    public ServicePaiement(PaiementRepository paiements,
                           TransitionPaiementRepository transitions,
                           RegistrePasserelles passerelles,
                           ServiceIdempotence idempotence,
                           ServiceAudit audit,
                           ServiceFacturation facturation,
                           @Lazy ServicePaiement self) {
        this.paiements = paiements;
        this.transitions = transitions;
        this.passerelles = passerelles;
        this.idempotence = idempotence;
        this.audit = audit;
        this.facturation = facturation;
        // Auto-référence via proxy : l'appel self.executer(...) déclenche bien la transaction
        // (une invocation this.executer(...) court-circuiterait l'AOP transactionnelle).
        this.self = self;
    }

    /**
     * Initie un paiement de façon idempotente. Un rejeu de la même {@code cleIdempotence} renvoie le
     * paiement déjà créé sans réexécuter la passerelle ({@code rejoue = true}).
     */
    public ResultatInitiation initier(CommandePaiement cmd, String cleIdempotence) {
        // 1re barrière de rejeu : la clé a-t-elle déjà produit un paiement ?
        var existant = paiements.findByIdempotencyKey(cleIdempotence);
        if (existant.isPresent()) {
            return new ResultatInitiation(existant.get(), true);
        }

        // Verrou Redis : sérialise les traitements concurrents de la même clé.
        if (!idempotence.acquerir(cleIdempotence)) {
            // Un autre thread traite peut-être la même clé et vient de committer : on revérifie.
            return paiements.findByIdempotencyKey(cleIdempotence)
                    .map(p -> new ResultatInitiation(p, true))
                    .orElseThrow(() -> new ConflitIdempotenceException(cleIdempotence));
        }

        try {
            // Le verrou est relâché APRÈS le commit de la transaction (executer proxifié).
            return self.executer(cmd, cleIdempotence);
        } finally {
            idempotence.liberer(cleIdempotence);
        }
    }

    @Transactional
    public ResultatInitiation executer(CommandePaiement cmd, String cleIdempotence) {
        // Revérification sous verrou (course entre la 1re lecture et l'acquisition du verrou).
        var deja = paiements.findByIdempotencyKey(cleIdempotence);
        if (deja.isPresent()) {
            return new ResultatInitiation(deja.get(), true);
        }

        Paiement paiement = paiements.save(new Paiement(
                cleIdempotence, cmd.correlationId(), cmd.montant(),
                cmd.devise() == null ? "XOF" : cmd.devise(), cmd.canal(), cmd.objet(),
                masquer(cmd.telephone()), cmd.etablissementRef(), cmd.patientRef()));

        auditer("PaymentInitiated", paiement, Map.of("montant", paiement.getMontant(), "canal", paiement.getCanal()));

        // INITIATED → PENDING → PROCESSING (demande envoyée à la passerelle).
        appliquer(paiement, PaiementStatut.PENDING, "Demande de paiement créée");
        auditer("PaymentPending", paiement, Map.of());
        appliquer(paiement, PaiementStatut.PROCESSING, "Transmission à la passerelle");

        ResultatPaiement res = passerelles.pour(cmd.canal()).payer(new RequetePaiement(
                paiement.getId().toString(), paiement.getMontant(), paiement.getDevise(),
                paiement.getCanal(), paiement.getObjet(), cmd.telephone(), paiement.getCorrelationId()));

        if (res.statut() == PaiementStatut.SUCCESS) {
            paiement.setProviderRef(res.referenceOperateur());
            paiement.setConfirmedAt(Instant.now());
            // Règlement d'une facture (§7.3) : impute le montant et met à jour son statut. Une facture
            // introuvable annule toute la transaction (aucun débit réel — paiement simulé).
            if (cmd.factureId() != null) {
                paiement.setFactureId(cmd.factureId());
                facturation.enregistrerReglement(cmd.factureId(), paiement.getMontant());
            }
            appliquer(paiement, PaiementStatut.SUCCESS, "Paiement confirmé par la passerelle");
            auditer("PaymentConfirmed", paiement, Map.of("referenceOperateur", nz(res.referenceOperateur())));
        } else {
            appliquer(paiement, PaiementStatut.FAILED, "Paiement refusé par la passerelle");
            auditer("PaymentFailed", paiement, Map.of("message", nz(res.message())));
        }

        return new ResultatInitiation(paiement, false);
    }

    @Transactional(readOnly = true)
    public Paiement trouver(UUID id) {
        return paiements.findById(id).orElseThrow(() -> new PaiementIntrouvableException(id.toString()));
    }

    @Transactional(readOnly = true)
    public java.util.List<TransitionPaiement> transitionsDe(UUID id) {
        trouver(id); // 404 si le paiement n'existe pas
        return transitions.findByPaymentIdOrderByCreatedAtAsc(id);
    }

    /** Rembourse un paiement réussi (SUCCESS → REFUNDED). */
    @Transactional
    public Paiement rembourser(UUID id, String motif) {
        Paiement paiement = trouver(id);
        // La machine à états refuse tout remboursement d'un paiement non abouti.
        MachineEtatsPaiement.verifier(paiement.getStatut(), PaiementStatut.REFUNDED);

        ResultatRemboursement res = passerelles.pour(paiement.getCanal()).rembourser(new RequeteRemboursement(
                paiement.getId().toString(), paiement.getProviderRef(), paiement.getMontant(), motif));

        if (!res.reussi()) {
            throw new IllegalStateException("Remboursement refusé par la passerelle : " + res.message());
        }
        appliquer(paiement, PaiementStatut.REFUNDED, motif == null ? "Remboursement" : motif);
        auditer("PaymentRefunded", paiement, Map.of("motif", nz(motif)));
        return paiement;
    }

    /** Applique une transition validée par la machine à états et la persiste. */
    private void appliquer(Paiement paiement, PaiementStatut vers, String raison) {
        PaiementStatut de = paiement.getStatut();
        MachineEtatsPaiement.verifier(de, vers);
        transitions.save(new TransitionPaiement(paiement.getId(), de, vers, raison));
        paiement.setStatut(vers);
        paiements.save(paiement);
    }

    private void auditer(String evenement, Paiement paiement, Map<String, Object> extra) {
        Map<String, Object> payload = new HashMap<>(extra);
        payload.put("statut", paiement.getStatut().name());
        audit.enregistrer(evenement, "payment", paiement.getId().toString(), payload);
    }

    /** Masque le numéro : ne conserve que les 2 derniers chiffres (CDC_06 §4.3). */
    private static String masquer(String telephone) {
        if (telephone == null || telephone.length() < 2) {
            return null;
        }
        String fin = telephone.substring(telephone.length() - 2);
        return "*".repeat(Math.max(0, telephone.length() - 2)) + fin;
    }

    private static String nz(String v) {
        return v == null ? "" : v;
    }
}
