package ci.masante.payment.config;

import org.springframework.context.annotation.Configuration;
import org.springframework.scheduling.annotation.EnableScheduling;

/** Active la planification des batchs (CDC_06 §12 : traitements lourds en batch planifié). */
@Configuration
@EnableScheduling
public class ConfigurationPlanification {
}
