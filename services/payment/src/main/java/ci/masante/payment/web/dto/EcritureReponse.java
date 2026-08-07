package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.EcritureReversement;

import java.time.LocalDate;
import java.util.List;
import java.util.UUID;

/** Écriture du grand livre (en-tête + jambes). */
public record EcritureReponse(UUID ecritureId, String typeEcriture, LocalDate dateComptable,
                              UUID ecritureExtourneeId, String creePar, List<LigneGrandLivreReponse> lignes) {

    public static EcritureReponse de(EcritureReversement e, List<LigneGrandLivreReponse> lignes) {
        return new EcritureReponse(e.getEcritureId(), e.getTypeEcriture().name(), e.getDateComptable(),
                e.getEcritureExtourneeId(), e.getCreePar(), lignes);
    }
}
