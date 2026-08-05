package ci.masante.payment.web.dto;

import java.util.List;

/** Un run avec le détail de ses écarts. */
public record ControleRunDetailReponse(ControleRunReponse run, List<ControleEcartReponse> ecarts) {
}
