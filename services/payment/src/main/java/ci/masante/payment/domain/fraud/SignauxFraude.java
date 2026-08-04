package ci.masante.payment.domain.fraud;

/**
 * Signaux mesurés pour une opération sortante (CDC_06 §6.4). Fournis au moteur de règles : celui-ci
 * ne lit aucun état, il évalue à partir de ces valeurs (frontière, testable).
 */
public record SignauxFraude(
        int nbOpsFenetre,       // opérations sortantes déjà abouties sur la fenêtre de vélocité
        long cumuleFenetre,     // montant déjà débité sur la fenêtre de cumul
        int echecsPinRecents,   // échecs de PIN récents
        long montantOperation   // montant de l'opération en cours
) {
}
