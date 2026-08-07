package ci.masante.payment.domain.model;

/**
 * Cycle de vie d'un relevé de reversement (CDC_06 §11).
 *
 * <p>En P5.5a : {@code CALCULE → APPROUVE}, et {@code → ANNULE} depuis l'un ou l'autre tant que rien
 * n'est exécuté (approuver n'engage aucun mouvement). {@code EN_COURS / EXECUTE / ECHOUE} sont
 * réservés au décaissement de P5.5b-2. {@code REJETE} (P5.5b-1) : refus par l'approbateur, distinct
 * d'{@code ANNULE} (annulation par le gestionnaire).</p>
 *
 * <p>Enum <b>backend-only</b> pour l'instant : promu dans {@code @masante/shared} (source unique)
 * quand l'écran d'administration web le consommera — pas avant (ne pas coder contre un consommateur
 * inexistant).</p>
 */
public enum ReversementStatut {
    CALCULE,
    APPROUVE,
    EN_COURS,
    EXECUTE,
    ECHOUE,
    ANNULE,
    REJETE
}
