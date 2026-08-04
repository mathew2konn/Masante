package ci.masante.payment.domain.fraud;

/**
 * Motifs de suspicion détectés par règles (CDC_06 §6.4). La détection par IA (géolocalisation
 * incohérente, fraude multi-comptes…) relève du futur {@code fraud-detection-service} (CDC_05).
 */
public enum MotifFraude {
    /** Trop d'opérations sortantes sur une courte fenêtre (vélocité). */
    VELOCITE_ELEVEE,
    /** Montant cumulé débité anormalement élevé sur la fenêtre. */
    MONTANT_CUMULE_ANORMAL,
    /** Échecs de PIN répétés récents. */
    ECHECS_PIN_REPETES
}
