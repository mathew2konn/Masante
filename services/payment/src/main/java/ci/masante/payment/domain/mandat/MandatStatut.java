package ci.masante.payment.domain.mandat;

/**
 * États d'un mandat récurrent (CDC_06 §5.4). Fourni par le backend, jamais déduit par le front (§0.1).
 * {@code ANNULE} et {@code EXPIRE} sont terminaux. Backend-only pour l'instant (à promouvoir dans
 * {@code @masante/shared} quand un écran le consommera — même logique qu'ADR-014/015/016).
 */
public enum MandatStatut {
    ACTIF,
    SUSPENDU,
    ANNULE,
    EXPIRE
}
