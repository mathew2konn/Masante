package ci.masante.payment.domain.model;

/**
 * Cycle de vie d'une tentative de décaissement (CDC_06 §11, P5.5b-2).
 * <ul>
 *   <li>{@code EN_COURS} — versement engagé auprès de la passerelle (fonds « en vol »).</li>
 *   <li>{@code EXECUTE}  — versement confirmé par la passerelle (écriture de DÉCAISSEMENT postée).</li>
 *   <li>{@code ECHOUE}   — versement refusé par la passerelle (rien n'est parti ; rejouable).</li>
 * </ul>
 *
 * <p>Distinct de {@link ReversementStatut} (état du relevé) qui reprend les mêmes libellés au niveau du
 * relevé ; ce type porte le statut d'UNE tentative dans le registre local {@code reversement_decaissement}.
 * Enum <b>backend-only</b> (promu dans {@code @masante/shared} le jour où un écran le consomme).</p>
 */
public enum DecaissementStatut {
    EN_COURS,
    EXECUTE,
    ECHOUE
}
