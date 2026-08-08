package ci.masante.payment.domain.reversement.rapprochement;

/**
 * Taxonomie des écarts du rapprochement à DEUX sources « factures ↔ reversements » (P5.5c, CDC_06 §11).
 *
 * <p>Contrairement à l'auditeur d'intégrité INTERNE (P5.3b-4, une seule source : la base confrontée à
 * elle-même), ce rapprochement confronte deux sous-systèmes maintenus INDÉPENDAMMENT : la
 * <b>facturation</b> (source A — factures {@code PAYEE} imputables, remboursements carte {@code REUSSI})
 * et les <b>reversements</b> (source B — lignes de relevé actives). Le bras « opérateurs ↔ MASANTÉ »
 * reste différé (aucun relevé opérateur réel, FT5 ; ADR-014).</p>
 *
 * <p>Mapping avec la taxonomie générique d'ADR-014 entre parenthèses. DÉTECTION SEULE : ces écarts ne
 * déclenchent jamais de correction (CDC_06 §11). Enum backend-only, à promouvoir dans {@code @masante/shared}
 * quand un écran d'administration le consommera.</p>
 */
public enum TypeEcartRapprochement {

    /**
     * (MANQUANT_COTE_REVERSEMENT) Une pièce due — facture {@code PAYEE} au réglé &gt; 0, ou remboursement
     * {@code REUSSI} — soldée depuis plus longtemps que le délai de grâce, n'apparaît sur AUCUNE ligne de
     * relevé active : de l'argent dû à un établissement tombé dans une faille.
     */
    PIECE_NON_REVERSEE,

    /**
     * (MANQUANT_COTE_PLATEFORME) Une ligne de relevé active référence une pièce qui n'existe pas / n'est
     * plus dans l'état attendu ({@code PAYEE}/{@code REUSSI}) / dont l'établissement diverge de celui du
     * relevé : le sous-système reversement pointe une pièce fantôme.
     */
    REVERSEMENT_SANS_PIECE,

    /**
     * (MONTANT_DIVERGENT) Une ligne active impute un montant différent du montant COURANT de la pièce
     * côté facturation : la pièce a bougé après son imputation.
     */
    MONTANT_REVERSE_DIVERGENT
}
