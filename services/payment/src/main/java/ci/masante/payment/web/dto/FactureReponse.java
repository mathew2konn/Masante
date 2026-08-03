package ci.masante.payment.web.dto;

import ci.masante.payment.domain.coverage.TypePriseEnCharge;
import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.domain.model.FactureLigne;
import ci.masante.payment.domain.model.FactureStatut;

import java.time.Instant;
import java.util.List;
import java.util.UUID;

/**
 * Vue publique d'une facture (CDC_06 §7). Le front N'AFFICHE que ces montants — invariant
 * {@code montantCouvert + resteAPayer == montantTtc}.
 */
public record FactureReponse(
        UUID id,
        String numero,
        String etablissementRef,
        String patientRef,
        int exercice,
        String devise,
        long sousTotalHt,
        long totalRemises,
        long totalTva,
        long montantTtc,
        TypePriseEnCharge couvertureType,
        Integer couvertureTaux,
        long montantCouvert,
        long resteAPayer,
        long montantRegle,
        FactureStatut statut,
        String hashIntegrite,
        int versionNumero,
        UUID origineFactureId,
        UUID remplaceeParId,
        boolean signee,
        String signatureAlgo,
        Instant createdAt,
        List<LigneReponse> lignes
) {
    public static FactureReponse de(Facture f, List<FactureLigne> lignes) {
        List<LigneReponse> l = lignes.stream().map(LigneReponse::de).toList();
        return new FactureReponse(
                f.getId(), f.getNumero(), f.getEtablissementRef(), f.getPatientRef(), f.getExercice(),
                f.getDevise(), f.getSousTotalHt(), f.getTotalRemises(), f.getTotalTva(), f.getMontantTtc(),
                f.getCouvertureType(), f.getCouvertureTaux(), f.getMontantCouvert(), f.getResteAPayer(),
                f.getMontantRegle(), f.getStatut(), f.getHashIntegrite(),
                f.getVersionNumero(), f.getOrigineFactureId(), f.getRemplaceeParId(),
                f.getSignature() != null, f.getSignatureAlgo(), f.getCreatedAt(), l);
    }

    public record LigneReponse(
            String libelle,
            int quantite,
            long prixUnitaire,
            long remise,
            int tauxTva,
            long montantHt,
            long montantTva,
            long montantTtc
    ) {
        static LigneReponse de(FactureLigne l) {
            return new LigneReponse(l.getLibelle(), l.getQuantite(), l.getPrixUnitaire(), l.getRemise(),
                    l.getTauxTva(), l.getMontantHt(), l.getMontantTva(), l.getMontantTtc());
        }
    }
}
