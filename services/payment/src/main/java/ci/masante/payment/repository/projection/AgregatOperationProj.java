package ci.masante.payment.repository.projection;

import java.util.UUID;

/** Agrégat d'une opération wallet : nombre d'écritures et leur somme (contrôle double écriture). */
public interface AgregatOperationProj {
    UUID getOperationId();

    long getNombre();

    long getSomme();
}
