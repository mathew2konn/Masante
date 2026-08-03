package ci.masante.payment;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;

/**
 * Microservice Paiement MASANTÉ (CDC_06, ADR-013).
 *
 * <p>P5.1 — socle : machine à états stricte, idempotence, audit immuable à hachage chaîné,
 * interface {@code PaymentGateway} (OCP) avec adaptateur simulé, moteur de prise en charge
 * CNAM/assurance. PAIEMENT SIMULÉ : aucune passerelle Mobile Money réelle n'est branchée.</p>
 */
@SpringBootApplication
public class PaymentApplication {

    public static void main(String[] args) {
        SpringApplication.run(PaymentApplication.class, args);
    }
}
