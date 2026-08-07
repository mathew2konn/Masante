package ci.masante.payment.domain.reversement;

import ci.masante.payment.domain.model.CompteReversement;
import ci.masante.payment.domain.model.SensEcriture;

/** Jambe d'écriture calculée (partie double). {@code montant} toujours &gt; 0 ; le sens porte le signe. */
public record JambeCalculee(int sequence, CompteReversement compte, SensEcriture sens, long montant, String libelle) {
}
