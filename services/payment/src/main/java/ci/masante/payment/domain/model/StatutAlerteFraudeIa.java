package ci.masante.payment.domain.model;

/**
 * Cycle de vie d'une alerte de fraude IA côté contrôleur plateforme. {@code OUVERTE} à la création ;
 * {@code REVUE} lorsqu'un {@code ADMIN_FINANCE} l'a traitée. Détection seule : aucune action automatique
 * n'est déclenchée par ces états — un humain décide (ADR-017). Backend-only en B1 (promu shared en B2).
 */
public enum StatutAlerteFraudeIa {
    OUVERTE,
    REVUE
}
