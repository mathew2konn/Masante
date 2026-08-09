package ci.masante.payment.service;

import ci.masante.payment.repository.RequetesSignauxFraude;
import ci.masante.payment.repository.projection.ActePrincipalProj;
import ci.masante.payment.repository.projection.SignauxFactureProj;
import ci.masante.payment.web.dto.SignauxFactureReponse;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Duration;
import java.time.Instant;
import java.time.ZoneOffset;
import java.time.temporal.ChronoUnit;
import java.util.List;
import java.util.Optional;

/**
 * EXTRACTION des signaux de facturation pour la détection de fraude (CDC_05, incrément A). Assemble, en
 * <b>lecture seule</b>, le vecteur de signaux d'une facture à partir du domaine paiement — exactement le
 * contrat {@code SignalFacturation} consommé par le microservice fraude (Python). <b>Aucune décision de
 * fraude ici</b> : ce service projette et agrège des DONNÉES ; le jugement (règles + ML + SHAP) reste
 * dans le service fraude. Le service paiement, propriétaire de son schéma, expose ; la fraude ne lit
 * jamais ces tables (ADR-014).
 *
 * <p>Cut-off {@code T} ({@code asOf}, défaut = maintenant) : toutes les fenêtres (30 j / 7 j / 24 h / 1 h,
 * journée) sont bornées à T pour la reproductibilité. Fuseau UTC = heure locale de Côte d'Ivoire (GMT).</p>
 */
@Service
public class ServiceSignauxFraude {

    private final RequetesSignauxFraude requetes;

    public ServiceSignauxFraude(RequetesSignauxFraude requetes) {
        this.requetes = requetes;
    }

    /** Extrait les signaux d'une facture (par numéro) au cut-off {@code asOf} (défaut = maintenant). */
    @Transactional(readOnly = true)
    public SignauxFactureReponse extraire(String reference, Instant asOf) {
        Instant t = asOf != null ? asOf : Instant.now();
        SignauxFactureProj f = requetes.factureParNumero(reference)
                .orElseThrow(() -> new FactureIntrouvableException(reference));

        // Acte principal (ligne au TTC le plus élevé) + référentiel + répétition dans la journée.
        long montantActe = 0;
        long montantActeReference = 0;
        long nbActesIdentiquesJour = 0;
        Optional<ActePrincipalProj> acte = requetes.actePrincipal(f.getId());
        if (acte.isPresent()) {
            montantActe = acte.get().getMontant();
            String libelle = acte.get().getLibelle();
            montantActeReference = requetes.moyenneReferenceActe(libelle, t);
            Instant jourDebut = t.atZone(ZoneOffset.UTC).toLocalDate().atStartOfDay(ZoneOffset.UTC).toInstant();
            Instant jourFin = jourDebut.plus(1, ChronoUnit.DAYS);
            nbActesIdentiquesJour = requetes.nbActesIdentiquesJour(
                    f.getEtablissementRef(), libelle, jourDebut, jourFin);
        }

        long nbFacturesEtablissement30j = requetes.nbFacturesEtablissement(
                f.getEtablissementRef(), t.minus(30, ChronoUnit.DAYS), t);

        // Signaux keyés patient : absents si la facture n'a pas de patient identifié.
        long nbRemboursementsCarte7j = 0;
        long montantCumuleWallet24h = 0;
        long nbOpsWallet1h = 0;
        String patient = f.getPatientRef();
        if (patient != null && !patient.isBlank()) {
            nbRemboursementsCarte7j = requetes.nbRemboursementsCarte(patient, t.minus(7, ChronoUnit.DAYS), t);
            montantCumuleWallet24h = requetes.cumulWallet(patient, t.minus(24, ChronoUnit.HOURS), t);
            nbOpsWallet1h = requetes.nbOpsWallet(patient, t.minus(1, ChronoUnit.HOURS), t);
        }

        // Délai facture→règlement et heure de l'opération de référence (règlement si réglée, sinon émission).
        Optional<Instant> reglement = requetes.confirmationReglement(f.getId());
        Instant instantOperation = reglement.orElse(f.getCreatedAt());
        int heureOperation = instantOperation.atZone(ZoneOffset.UTC).getHour();
        long delaiFacturePaiementMinutes = 0;
        if (reglement.isPresent()) {
            long minutes = Duration.between(f.getCreatedAt(), reglement.get()).toMinutes();
            delaiFacturePaiementMinutes = Math.max(0, minutes);
        }

        return new SignauxFactureReponse(
                f.getReference(), f.getEtablissementRef(),
                f.getMontantTtc(), f.getMontantCouvert(), f.getResteAPayer(),
                montantActe, montantActeReference,
                nbFacturesEtablissement30j, nbActesIdentiquesJour, nbRemboursementsCarte7j,
                montantCumuleWallet24h, nbOpsWallet1h, heureOperation, delaiFacturePaiementMinutes);
    }

    /** Extrait un LOT de factures au MÊME cut-off T (snapshot cohérent). Réf inconnue → 404 (erreur d'appel). */
    @Transactional(readOnly = true)
    public List<SignauxFactureReponse> extraireLot(List<String> references, Instant asOf) {
        Instant t = asOf != null ? asOf : Instant.now();
        return references.stream().map(r -> extraire(r, t)).toList();
    }
}
