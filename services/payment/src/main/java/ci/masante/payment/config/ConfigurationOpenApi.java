package ci.masante.payment.config;

import io.swagger.v3.oas.models.OpenAPI;
import io.swagger.v3.oas.models.info.Info;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

/** Métadonnées OpenAPI (preuve G2). Swagger UI : /swagger-ui.html — JSON : /v3/api-docs. */
@Configuration
public class ConfigurationOpenApi {

    @Bean
    public OpenAPI apiPaiement() {
        return new OpenAPI().info(new Info()
                .title("MASANTÉ — Service Paiement")
                .version("0.1.0 (P5.1)")
                .description("""
                        Microservice paiement (CDC_06, ADR-013). PAIEMENT SIMULÉ : aucune passerelle \
                        Mobile Money réelle. Socle : machine à états stricte, idempotence \
                        (Idempotency-Key), audit immuable à hachage chaîné, moteur de prise en charge \
                        CNAM/assurance (couverture, ticket modérateur, reste à charge)."""));
    }
}
