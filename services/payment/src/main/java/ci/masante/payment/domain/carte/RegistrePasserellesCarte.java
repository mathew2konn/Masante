package ci.masante.payment.domain.carte;

import org.springframework.stereotype.Component;

import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.function.Function;
import java.util.stream.Collectors;

/**
 * Dispatch des passerelles carte par PSP (CDC_06 §6.1, ADR-015) — OCP : Spring injecte toutes les
 * {@link PasserelleCarte}, indexées par {@link PasserelleCarte#psp()}. Ajouter un PSP = ajouter un bean.
 * <b>Aucun {@code if psp == …}</b> nulle part (interdit #5).
 */
@Component
public class RegistrePasserellesCarte {

    private final Map<String, PasserelleCarte> parPsp;

    public RegistrePasserellesCarte(List<PasserelleCarte> passerelles) {
        this.parPsp = passerelles.stream()
                .collect(Collectors.toMap(PasserelleCarte::psp, Function.identity()));
    }

    public PasserelleCarte pour(String psp) {
        PasserelleCarte p = parPsp.get(psp);
        if (p == null) {
            throw new PasserelleInconnue(psp);
        }
        return p;
    }

    /** PSP enregistrés (itération de la réconciliation quotidienne §6.3). */
    public Set<String> psps() {
        return parPsp.keySet();
    }
}
