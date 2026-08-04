package ci.masante.payment.domain.fraud;

/**
 * Paliers de risque (CDC_06 §6.4) — progressifs, jamais un gel binaire (contexte santé : une urgence
 * médicale génère des paiements rapprochés légitimes).
 */
public enum PalierFraude {
    /** Sous le seuil d'alerte : rien à signaler. */
    NORMAL,
    /** L'opération passe, mais une alerte est enregistrée pour surveillance. */
    ALERTE,
    /** Re-authentification forte (OTP) exigée ; l'opération passe si vérifiée. */
    CHALLENGE,
    /** Score élevé : l'opération est bloquée et le portefeuille gelé. */
    GEL
}
