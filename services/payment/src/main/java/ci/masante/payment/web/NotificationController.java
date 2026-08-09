package ci.masante.payment.web;

import ci.masante.payment.service.ServiceNotifications;
import ci.masante.payment.web.dto.NotificationReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.constraints.NotBlank;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.Map;

/**
 * API des notifications sortantes (CDC_06 §5.4, Outbox). LIVRAISON SIMULÉE (FT5). Le relais est
 * normalement déclenché par un job planifié ; l'endpoint manuel sert à l'exploitation et à la preuve G2.
 */
@RestController
@RequestMapping("/api/v1/notifications")
@Validated
@Tag(name = "Notifications", description = "Notifications sortantes (préavis de prélèvement) — outbox, livraison simulée")
public class NotificationController {

    private final ServiceNotifications notifications;

    public NotificationController(ServiceNotifications notifications) {
        this.notifications = notifications;
    }

    @PostMapping("/relayer")
    @Operation(summary = "Relayer les notifications en attente (livraison simulée) — endpoint d'exploitation")
    public Map<String, Integer> relayer() {
        return Map.of("livrees", notifications.envoyerEnAttente());
    }

    @GetMapping
    @Operation(summary = "Lister les notifications d'un destinataire (outbox)")
    public List<NotificationReponse> lister(@RequestHeader("X-Utilisateur-Id") @NotBlank String destinataireRef) {
        return notifications.pourDestinataire(destinataireRef).stream().map(NotificationReponse::de).toList();
    }
}
