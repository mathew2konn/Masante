package ci.masante.payment.service;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.data.redis.core.StringRedisTemplate;
import org.springframework.stereotype.Service;

import java.time.Duration;

/**
 * Verrou d'idempotence Redis (CDC_06 §9.6). Empêche deux traitements concurrents de la MÊME clé
 * (double-clic, rejeu). C'est la 1re barrière ; la 2de est la contrainte d'unicité PostgreSQL sur
 * {@code payments.idempotency_key} (protège même si Redis tombe).
 */
@Service
public class ServiceIdempotence {

    private static final String PREFIXE = "pay:lock:";

    private final StringRedisTemplate redis;
    private final Duration ttl;

    public ServiceIdempotence(StringRedisTemplate redis,
                              @Value("${masante.payment.idempotency-lock-seconds:30}") long lockSeconds) {
        this.redis = redis;
        this.ttl = Duration.ofSeconds(lockSeconds);
    }

    /** @return true si le verrou a été pris ; false s'il est déjà détenu. */
    public boolean acquerir(String cle) {
        Boolean ok = redis.opsForValue().setIfAbsent(PREFIXE + cle, "1", ttl);
        return Boolean.TRUE.equals(ok);
    }

    public void liberer(String cle) {
        redis.delete(PREFIXE + cle);
    }
}
