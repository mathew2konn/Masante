package ci.masante.payment.service;

import ci.masante.payment.domain.mandat.Periodicite;
import ci.masante.payment.domain.model.ObjetPaiement;

import java.time.LocalDate;
import java.util.UUID;

/**
 * Commande de création d'un mandat récurrent (issue de la requête HTTP validée, §5.4). Le montant et la
 * périodicité sont des DONNÉES ; le calcul des échéances est backend.
 */
public record CommandeMandat(
        String utilisateurRef,
        UUID carteId,
        ObjetPaiement objet,
        String libelle,
        long montant,
        String codeDevise,
        Periodicite periodicite,
        LocalDate dateDebut,
        LocalDate dateFin,
        int preavisJours,
        String etablissementRef,
        String patientRef,
        String acteur
) {
}
