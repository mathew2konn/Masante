package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.Mandat;
import ci.masante.payment.domain.model.MandatEcheance;

import java.time.LocalDate;
import java.util.List;
import java.util.UUID;

/** Vue d'un mandat récurrent (§5.4). Le statut est FOURNI par le backend, jamais déduit par le front. */
public record MandatReponse(
        UUID id,
        String utilisateurRef,
        UUID carteId,
        String psp,
        String objet,
        String libelle,
        long montant,
        String devise,
        String periodicite,
        LocalDate dateDebut,
        LocalDate dateFin,
        LocalDate prochaineEcheance,
        int preavisJours,
        String statut,
        int sequenceCourante,
        List<MandatEcheanceReponse> echeances
) {
    public static MandatReponse de(Mandat m) {
        return construire(m, null);
    }

    public static MandatReponse avecEcheances(Mandat m, List<MandatEcheance> echeances) {
        return construire(m, echeances.stream().map(MandatEcheanceReponse::de).toList());
    }

    private static MandatReponse construire(Mandat m, List<MandatEcheanceReponse> echeances) {
        return new MandatReponse(m.getId(), m.getUtilisateurRef(), m.getCarteId(), m.getPsp(),
                m.getObjet().name(), m.getLibelle(), m.getMontant(), m.getDevise(),
                m.getPeriodicite().name(), m.getDateDebut(), m.getDateFin(), m.getProchaineEcheance(),
                m.getPreavisJours(), m.getStatut().name(), m.getSequenceCourante(), echeances);
    }
}
