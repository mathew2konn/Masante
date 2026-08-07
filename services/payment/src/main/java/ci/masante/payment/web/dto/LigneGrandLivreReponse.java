package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.LigneGrandLivre;

/** Jambe d'écriture du grand livre reversement. */
public record LigneGrandLivreReponse(short sequence, String compte, String sens, long montant, String libelle) {

    public static LigneGrandLivreReponse de(LigneGrandLivre l) {
        return new LigneGrandLivreReponse(l.getSequence(), l.getCompte().name(), l.getSens().name(),
                l.getMontant(), l.getLibelle());
    }
}
