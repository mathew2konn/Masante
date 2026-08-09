package ci.masante.payment.domain.model;

/**
 * Niveau de suspicion rendu par le fraud-detection-service (CDC_05). Backend-only pour B1 ; sera promu
 * dans {@code @masante/shared} quand l'écran admin Next (B2) le consommera (même logique qu'ADR-014/015).
 * {@code NORMAL} n'est jamais persisté en alerte : seules {@code SUSPECT}/{@code TRES_SUSPECT} remontent.
 */
public enum NiveauFraudeIa {
    NORMAL,
    SUSPECT,
    TRES_SUSPECT
}
