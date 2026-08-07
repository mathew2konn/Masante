package ci.masante.payment.web.dto;

import java.util.List;

/** Relevé + ses lignes (snapshot pièce par pièce). */
public record ReversementReleveDetailReponse(ReversementReleveReponse releve,
                                             List<LigneReversementReponse> lignes) {
}
