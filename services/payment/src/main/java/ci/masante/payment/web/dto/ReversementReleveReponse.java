package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.ReversementReleve;

import java.time.Instant;
import java.util.UUID;

/** En-tête d'un relevé de reversement (§11). Tous les montants en XOF entiers, calculés backend. */
public record ReversementReleveReponse(
        UUID id, String numero, String etablissementRef, int exercice,
        Instant periodeDebut, Instant periodeFin, Instant cutOffT, int tentative, String devise,
        long montantBrutDu, int tauxCommissionBps, long montantCommission, long montantRembourse,
        long reportAnterieur, long montantNetAReverser, long soldeReporte,
        String statut, UUID relevePrecedentId, String hashIntegrite,
        String calculePar, Instant calculeA, String approuvePar, Instant approuveA,
        String annulePar, Instant annuleA, String motifAnnulation) {

    public static ReversementReleveReponse de(ReversementReleve r) {
        return new ReversementReleveReponse(r.getId(), r.getNumero(), r.getEtablissementRef(), r.getExercice(),
                r.getPeriodeDebut(), r.getPeriodeFin(), r.getCutOffT(), r.getTentative(), r.getDevise(),
                r.getMontantBrutDu(), r.getTauxCommissionBps(), r.getMontantCommission(), r.getMontantRembourse(),
                r.getReportAnterieur(), r.getMontantNetAReverser(), r.getSoldeReporte(),
                r.getStatut().name(), r.getRelevePrecedentId(), r.getHashIntegrite(),
                r.getCalculePar(), r.getCalculeA(), r.getApprouvePar(), r.getApprouveA(),
                r.getAnnulePar(), r.getAnnuleA(), r.getMotifAnnulation());
    }
}
