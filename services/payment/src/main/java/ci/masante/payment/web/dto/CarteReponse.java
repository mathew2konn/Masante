package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.Carte;

import java.time.Instant;
import java.util.UUID;

/**
 * Vue publique d'une carte du vault (§5.2). FRONTIÈRE PCI : n'expose QUE des métadonnées non sensibles
 * (marque, 4 derniers chiffres, expiration) — jamais le token, l'empreinte ni l'identifiant client PSP.
 */
public record CarteReponse(
        UUID id,
        String psp,
        String marque,
        String last4,
        int expMois,
        int expAnnee,
        boolean parDefaut,
        Instant creeLe
) {
    public static CarteReponse de(Carte c) {
        return new CarteReponse(c.getId(), c.getPsp(), c.getMarque(), c.getLast4(),
                c.getExpMois(), c.getExpAnnee(), c.isParDefaut(), c.getCreeLe());
    }
}
