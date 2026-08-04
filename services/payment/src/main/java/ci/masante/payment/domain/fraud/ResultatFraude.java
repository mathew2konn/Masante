package ci.masante.payment.domain.fraud;

import java.util.List;

/** Résultat de l'évaluation : score, motifs déclenchés, palier retenu. */
public record ResultatFraude(int score, List<MotifFraude> motifs, PalierFraude palier) {
}
