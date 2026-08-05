package ci.masante.payment.domain.integrity;

/** Verdict d'un run de contrôle. OK = aucun écart ; ECARTS = au moins un écart détecté. */
public enum ControleStatut {
    OK,
    ECARTS
}
