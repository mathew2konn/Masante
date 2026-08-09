package ci.masante.payment.web.dto;

/**
 * SIGNAUX de facturation d'une pièce, extraits en lecture seule du domaine paiement et exposés à la
 * détection de fraude (CDC_05, incrément A). Miroir exact du contrat {@code SignalFacturation} du
 * microservice fraude (Python) : mêmes champs, sémantique identique. L'adaptateur Python normalise ces
 * champs (camelCase) vers son schéma (snake_case) — c'est lui la frontière anti-corruption (ADR-014).
 *
 * <p>Aucune décision de fraude ici : ce sont des DONNÉES agrégées. Montants en XOF entier.</p>
 */
public record SignauxFactureReponse(
        String reference,
        String etablissementRef,
        long montantTtc,
        long montantCouvert,
        long resteAPayer,
        long montantActe,
        long montantActeReference,
        long nbFacturesEtablissement30j,
        long nbActesIdentiquesJour,
        long nbRemboursementsCarte7j,
        long montantCumuleWallet24h,
        long nbOpsWallet1h,
        int heureOperation,
        long delaiFacturePaiementMinutes) {
}
