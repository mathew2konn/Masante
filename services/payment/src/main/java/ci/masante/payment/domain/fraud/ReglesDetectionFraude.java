package ci.masante.payment.domain.fraud;

import java.util.ArrayList;
import java.util.List;

/**
 * Moteur de détection de fraude par <b>règles déterministes</b> (CDC_06 §6.4) — <b>frontière</b> :
 * classe pure (sans Spring, sans base), testable en unitaire. Additionne des poids par motif
 * déclenché ; le palier découle du score (3 paliers, jamais un gel binaire). Aucune IA ne décide ici.
 */
public final class ReglesDetectionFraude {

    private ReglesDetectionFraude() {
    }

    public static ResultatFraude evaluer(SignauxFraude s, ParametresFraude p) {
        int score = 0;
        List<MotifFraude> motifs = new ArrayList<>();

        if (p.velociteMax() > 0 && s.nbOpsFenetre() >= p.velociteMax()) {
            score += p.poidsVelocite();
            motifs.add(MotifFraude.VELOCITE_ELEVEE);
        }
        if (p.cumuleMax() > 0 && s.cumuleFenetre() + s.montantOperation() > p.cumuleMax()) {
            score += p.poidsCumul();
            motifs.add(MotifFraude.MONTANT_CUMULE_ANORMAL);
        }
        if (p.echecsPinMax() > 0 && s.echecsPinRecents() >= p.echecsPinMax()) {
            score += p.poidsPin();
            motifs.add(MotifFraude.ECHECS_PIN_REPETES);
        }

        return new ResultatFraude(score, List.copyOf(motifs), palier(score, p));
    }

    private static PalierFraude palier(int score, ParametresFraude p) {
        if (score >= p.seuilGel()) {
            return PalierFraude.GEL;
        }
        if (score >= p.seuilChallenge()) {
            return PalierFraude.CHALLENGE;
        }
        if (score >= p.seuilAlerte()) {
            return PalierFraude.ALERTE;
        }
        return PalierFraude.NORMAL;
    }
}
