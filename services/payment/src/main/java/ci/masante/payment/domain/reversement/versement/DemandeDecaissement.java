package ci.masante.payment.domain.reversement.versement;

import ci.masante.payment.domain.model.TypeDestination;

/**
 * Ordre de versement soumis à une {@link PasserelleReversement}. {@code referenceInterne} est
 * DÉTERMINISTE par relevé → un vrai PSP déduplique aussi (anti-double-versement inter-couches).
 * {@code destinationClair} = MSISDN/IBAN déchiffré : présent UNIQUEMENT dans ce chemin de versement,
 * jamais journalisé, jamais renvoyé par une API.
 */
public record DemandeDecaissement(String referenceInterne, TypeDestination type, String destinationClair,
                                  long montant, String devise, String libelle) {
}
