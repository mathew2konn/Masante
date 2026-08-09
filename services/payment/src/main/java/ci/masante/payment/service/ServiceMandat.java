package ci.masante.payment.service;

import ci.masante.payment.domain.mandat.ActionMandat;
import ci.masante.payment.domain.mandat.MachineEtatsMandat;
import ci.masante.payment.domain.mandat.MandatStatut;
import ci.masante.payment.domain.mandat.StatutEcheance;
import ci.masante.payment.domain.notification.TypeNotification;
import ci.masante.payment.domain.model.Carte;
import ci.masante.payment.domain.model.Mandat;
import ci.masante.payment.domain.model.MandatEcheance;
import ci.masante.payment.repository.CarteRepository;
import ci.masante.payment.repository.MandatEcheanceRepository;
import ci.masante.payment.repository.MandatRepository;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Lazy;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Orchestration des mandats récurrents (CDC_06 §5.4). PAIEMENT SIMULÉ (FT5). Frontière (§0.1) : montant,
 * périodicité, calcul de la prochaine échéance, éligibilité et transitions sont calculés ICI (backend),
 * jamais dans le front.
 *
 * <p><b>Anti double-prélèvement</b> : l'exécution d'une échéance est sérialisée par un verrou Redis
 * ({@code Idempotency-Key}) + un verrou pessimiste sur l'échéance + la clé d'idempotence du paiement
 * (déterministe {@code mandat:<id>:<seq>}) + l'unicité {@code (mandat_id, numero_sequence)}.</p>
 *
 * <p><b>Notifications avant prélèvement</b> : le préavis est ENREGISTRÉ (date, montant) ; la livraison réelle
 * (push/SMS/mail) est différée — aucun canal de notification dans ce service (dette assumée).</p>
 */
@Service
public class ServiceMandat {

    /** Résultat interne de l'exécution d'une échéance (comptage). */
    public enum IssueEcheance { EXECUTEE, ECHOUEE, IGNOREE }

    /** Résumé d'un balayage d'exécution. */
    public record ResumeExecution(int total, int executees, int echouees, int ignorees) {
    }

    private final MandatRepository mandats;
    private final MandatEcheanceRepository echeances;
    private final CarteRepository cartes;
    private final ServiceCarte serviceCarte;
    private final ServiceIdempotence idempotence;
    private final ServiceAudit audit;
    private final ServiceNotifications notifications;
    private final int fenetrePreavisMaxJours;
    private final ServiceMandat self;

    public ServiceMandat(MandatRepository mandats,
                         MandatEcheanceRepository echeances,
                         CarteRepository cartes,
                         ServiceCarte serviceCarte,
                         ServiceIdempotence idempotence,
                         ServiceAudit audit,
                         ServiceNotifications notifications,
                         @Value("${masante.payment.mandats.fenetre-preavis-max-jours:30}") int fenetrePreavisMaxJours,
                         @Lazy ServiceMandat self) {
        this.mandats = mandats;
        this.echeances = echeances;
        this.cartes = cartes;
        this.serviceCarte = serviceCarte;
        this.idempotence = idempotence;
        this.audit = audit;
        this.notifications = notifications;
        this.fenetrePreavisMaxJours = fenetrePreavisMaxJours;
        this.self = self;
    }

    // ---------------------------------------------------------------------------------------------
    // Création
    // ---------------------------------------------------------------------------------------------

    public Mandat creer(CommandeMandat cmd, String cleIdempotence) {
        var existant = mandats.findByIdempotencyKey(cleIdempotence);
        if (existant.isPresent()) {
            return existant.get();
        }
        if (!idempotence.acquerir("mandat-create:" + cleIdempotence)) {
            return mandats.findByIdempotencyKey(cleIdempotence)
                    .orElseThrow(() -> new ConflitIdempotenceException(cleIdempotence));
        }
        try {
            return self.executerCreation(cmd, cleIdempotence);
        } finally {
            idempotence.liberer("mandat-create:" + cleIdempotence);
        }
    }

    @Transactional
    public Mandat executerCreation(CommandeMandat cmd, String cleIdempotence) {
        var deja = mandats.findByIdempotencyKey(cleIdempotence);
        if (deja.isPresent()) {
            return deja.get();
        }
        if (cmd.montant() <= 0) {
            throw new OperationMandatInvalideException("Montant de mandat invalide");
        }
        if (cmd.periodicite() == null || cmd.dateDebut() == null) {
            throw new OperationMandatInvalideException("Périodicité et date de début requises");
        }
        if (cmd.preavisJours() < 0) {
            throw new OperationMandatInvalideException("Préavis (jours) invalide");
        }
        if (cmd.dateFin() != null && cmd.dateFin().isBefore(cmd.dateDebut())) {
            throw new OperationMandatInvalideException("Date de fin antérieure à la date de début");
        }
        Carte carte = cartes.findByIdAndUtilisateurRefAndSupprimeLeIsNull(cmd.carteId(), cmd.utilisateurRef())
                .orElseThrow(() -> new OperationMandatInvalideException("Carte introuvable ou supprimée"));

        Mandat mandat = mandats.save(new Mandat(cleIdempotence, cmd.utilisateurRef(), carte.getId(), carte.getPsp(),
                cmd.objet(), cmd.libelle(), cmd.montant(), cmd.codeDevise(), cmd.periodicite(), cmd.dateDebut(),
                cmd.dateFin(), cmd.preavisJours(), cmd.etablissementRef(), cmd.patientRef(), cmd.acteur()));

        // Première échéance : séquence 1 à la date de début.
        echeances.save(new MandatEcheance(mandat.getId(), 1, cmd.dateDebut(), cmd.montant(), cmd.codeDevise()));
        mandat.avancer(1, cmd.dateDebut());
        mandats.save(mandat);

        auditerMandat("MandateCreated", mandat, Map.of(
                "montant", mandat.getMontant(), "periodicite", mandat.getPeriodicite().name(),
                "dateDebut", cmd.dateDebut().toString()));
        return mandat;
    }

    // ---------------------------------------------------------------------------------------------
    // Cycle de vie (suspendre / reprendre / annuler)
    // ---------------------------------------------------------------------------------------------

    @Transactional
    public Mandat appliquerAction(UUID mandatId, ActionMandat action, String acteur) {
        Mandat mandat = mandats.verrouiller(mandatId)
                .orElseThrow(() -> new MandatIntrouvableException(mandatId.toString()));
        if (!MachineEtatsMandat.estAutorisee(mandat.getStatut(), action)) {
            throw new OperationMandatInvalideException(
                    "Action " + action + " impossible depuis l'état " + mandat.getStatut());
        }
        MandatStatut vers = MachineEtatsMandat.transition(mandat.getStatut(), action);
        mandat.changerStatut(vers);
        mandats.save(mandat);
        auditerMandat("Mandate" + nomAction(action), mandat, Map.of("acteur", nz(acteur), "statut", vers.name()));
        return mandat;
    }

    // ---------------------------------------------------------------------------------------------
    // Préavis (notifications avant prélèvement — livraison différée)
    // ---------------------------------------------------------------------------------------------

    @Transactional
    public int poserPreavisDus(LocalDate aujourdhui) {
        LocalDate borne = aujourdhui.plusDays(fenetrePreavisMaxJours);
        int n = 0;
        for (MandatEcheance e : echeances.findByStatutAndDatePrevueLessThanEqual(StatutEcheance.PLANIFIEE, borne)) {
            Mandat mandat = mandats.findById(e.getMandatId()).orElse(null);
            if (mandat == null || mandat.getStatut() != MandatStatut.ACTIF) {
                continue;
            }
            // Préavis dû si l'échéance tombe dans la fenêtre propre au mandat.
            if (!e.getDatePrevue().isAfter(aujourdhui.plusDays(mandat.getPreavisJours()))) {
                e.marquerPreavis(Instant.now());
                echeances.save(e);
                // Outbox : notification AVANT prélèvement (§5.4), committée avec le préavis (livraison différée).
                notifications.emettre(TypeNotification.PRELEVEMENT_IMMINENT, "echeance", e.getId(),
                        mandat.getUtilisateurRef(), Map.of(
                                "montant", e.getMontant(), "devise", e.getDevise(),
                                "dateEcheance", e.getDatePrevue().toString(), "libelle", nz(mandat.getLibelle())));
                auditerMandat("MandatePreNotice", mandat, Map.of(
                        "echeance", e.getDatePrevue().toString(), "montant", e.getMontant()));
                n++;
            }
        }
        return n;
    }

    // ---------------------------------------------------------------------------------------------
    // Exécution des échéances (débit MIT SIMULÉ)
    // ---------------------------------------------------------------------------------------------

    public ResumeExecution executerEcheancesDues(LocalDate aujourdhui) {
        List<UUID> ids = echeances
                .findByStatutInAndDatePrevueLessThanEqual(
                        List.of(StatutEcheance.PLANIFIEE, StatutEcheance.PREAVIS), aujourdhui)
                .stream().map(MandatEcheance::getId).toList();
        int exec = 0;
        int echec = 0;
        int ignore = 0;
        for (UUID id : ids) {
            switch (executerEcheance(id)) {
                case EXECUTEE -> exec++;
                case ECHOUEE -> echec++;
                case IGNOREE -> ignore++;
            }
        }
        return new ResumeExecution(ids.size(), exec, echec, ignore);
    }

    /** Exécute UNE échéance de façon idempotente (verrou Redis par échéance + transaction dédiée). */
    public IssueEcheance executerEcheance(UUID echeanceId) {
        String verrou = "mandat-echeance:" + echeanceId;
        if (!idempotence.acquerir(verrou)) {
            return IssueEcheance.IGNOREE; // une exécution concurrente s'en charge
        }
        try {
            return self.executerEcheanceTx(echeanceId);
        } finally {
            idempotence.liberer(verrou);
        }
    }

    @Transactional
    public IssueEcheance executerEcheanceTx(UUID echeanceId) {
        MandatEcheance echeance = echeances.verrouiller(echeanceId).orElse(null);
        if (echeance == null || !echeance.estAExecuter()) {
            return IssueEcheance.IGNOREE; // déjà exécutée/échouée ou disparue → idempotent
        }
        Mandat mandat = mandats.verrouiller(echeance.getMandatId())
                .orElseThrow(() -> new MandatIntrouvableException(echeance.getMandatId().toString()));

        if (mandat.getStatut() == MandatStatut.SUSPENDU) {
            return IssueEcheance.IGNOREE; // reprise ultérieure : on laisse l'échéance planifiée
        }
        if (mandat.getStatut() != MandatStatut.ACTIF) {
            echeance.marquerSautee(); // ANNULE / EXPIRE : plus jamais prélevée
            echeances.save(echeance);
            return IssueEcheance.IGNOREE;
        }

        Carte carte = cartes.findById(mandat.getCarteId()).orElse(null);
        if (carte == null || carte.getSupprimeLe() != null) {
            echeance.marquerEchouee(null, null, "carte_indisponible", Instant.now());
            echeances.save(echeance);
            notifierEchec(mandat, echeance, "carte_indisponible");
            avancer(mandat, echeance);
            auditerMandat("MandateInstallmentFailed", mandat, Map.of(
                    "sequence", echeance.getNumeroSequence(), "codeRefus", "carte_indisponible"));
            return IssueEcheance.ECHOUEE;
        }

        String cleIdempotence = "mandat:" + mandat.getId() + ":" + echeance.getNumeroSequence();
        ResultatDebitMandat r = serviceCarte.debiterMandat(carte, echeance.getMontant(), echeance.getDevise(),
                mandat.getObjet(), mandat.getEtablissementRef(), mandat.getPatientRef(),
                "MANDAT-" + mandat.getId(), cleIdempotence);

        Instant maintenant = Instant.now();
        IssueEcheance issue;
        if (r.reussi()) {
            echeance.marquerExecutee(r.paiementId(), r.carteTransactionId(), maintenant);
            auditerMandat("MandateInstallmentExecuted", mandat, Map.of(
                    "sequence", echeance.getNumeroSequence(), "montant", echeance.getMontant(),
                    "paiement", r.paiementId().toString()));
            issue = IssueEcheance.EXECUTEE;
        } else {
            echeance.marquerEchouee(r.paiementId(), r.carteTransactionId(), r.codeRefus(), maintenant);
            notifierEchec(mandat, echeance, r.codeRefus());
            auditerMandat("MandateInstallmentFailed", mandat, Map.of(
                    "sequence", echeance.getNumeroSequence(), "codeRefus", nz(r.codeRefus())));
            issue = IssueEcheance.ECHOUEE;
        }
        echeances.save(echeance);
        // On avance la planification (succès comme échec) : un refus n'interrompt pas l'abonnement (relance = dette).
        avancer(mandat, echeance);
        return issue;
    }

    /**
     * Planifie l'échéance suivante à partir de la date de l'échéance traitée ; expire le mandat si la
     * prochaine dépasse la date de fin. Idempotent : l'unicité {@code (mandat_id, numero_sequence)} empêche
     * un doublon si deux avances concurrentes se produisaient.
     */
    private void avancer(Mandat mandat, MandatEcheance traitee) {
        LocalDate prochaine = mandat.getPeriodicite().prochaine(traitee.getDatePrevue());
        int seqSuivante = traitee.getNumeroSequence() + 1;

        if (mandat.finAtteinte(prochaine)) {
            mandat.changerStatut(MandatStatut.EXPIRE);
            mandats.save(mandat);
            auditerMandat("MandateExpired", mandat, Map.of("derniereSequence", traitee.getNumeroSequence()));
            return;
        }
        if (!echeances.existsByMandatIdAndNumeroSequence(mandat.getId(), seqSuivante)) {
            echeances.save(new MandatEcheance(
                    mandat.getId(), seqSuivante, prochaine, mandat.getMontant(), mandat.getDevise()));
        }
        mandat.avancer(seqSuivante, prochaine);
        mandats.save(mandat);
    }

    // ---------------------------------------------------------------------------------------------
    // Expiration par date de fin (job)
    // ---------------------------------------------------------------------------------------------

    @Transactional
    public int expirerMandatsEchus(LocalDate aujourdhui) {
        int n = 0;
        for (Mandat ref : mandats.findByStatutAndDateFinBefore(MandatStatut.ACTIF, aujourdhui)) {
            Mandat mandat = mandats.verrouiller(ref.getId()).orElse(null);
            if (mandat != null && mandat.getStatut() == MandatStatut.ACTIF && mandat.finAtteinte(aujourdhui)) {
                mandat.changerStatut(MandatStatut.EXPIRE);
                mandats.save(mandat);
                auditerMandat("MandateExpired", mandat, Map.of("cause", "date_fin"));
                n++;
            }
        }
        return n;
    }

    // ---------------------------------------------------------------------------------------------
    // Consultation
    // ---------------------------------------------------------------------------------------------

    @Transactional(readOnly = true)
    public Mandat consulter(UUID mandatId) {
        return mandats.findById(mandatId)
                .orElseThrow(() -> new MandatIntrouvableException(mandatId.toString()));
    }

    @Transactional(readOnly = true)
    public List<MandatEcheance> echeancesDe(UUID mandatId) {
        return echeances.findByMandatIdOrderByNumeroSequence(mandatId);
    }

    @Transactional(readOnly = true)
    public List<Mandat> listerParUtilisateur(String utilisateurRef) {
        return mandats.findByUtilisateurRefOrderByCreeLeDesc(utilisateurRef);
    }

    // ---------------------------------------------------------------------------------------------
    // Internes
    // ---------------------------------------------------------------------------------------------

    private void notifierEchec(Mandat mandat, MandatEcheance echeance, String codeRefus) {
        notifications.emettre(TypeNotification.PRELEVEMENT_ECHOUE, "echeance", echeance.getId(),
                mandat.getUtilisateurRef(), Map.of(
                        "montant", echeance.getMontant(), "devise", echeance.getDevise(),
                        "dateEcheance", echeance.getDatePrevue().toString(), "codeRefus", nz(codeRefus)));
    }

    private void auditerMandat(String evenement, Mandat mandat, Map<String, Object> extra) {
        var payload = new java.util.HashMap<String, Object>(extra);
        payload.put("statut", mandat.getStatut().name());
        payload.put("utilisateur", nz(mandat.getUtilisateurRef()));
        audit.enregistrer(evenement, "mandat", mandat.getId().toString(), payload);
    }

    private static String nomAction(ActionMandat action) {
        return switch (action) {
            case SUSPENDRE -> "Suspended";
            case REPRENDRE -> "Resumed";
            case ANNULER -> "Cancelled";
            case EXPIRER -> "Expired";
        };
    }

    private static String nz(String v) {
        return v == null ? "" : v;
    }
}
