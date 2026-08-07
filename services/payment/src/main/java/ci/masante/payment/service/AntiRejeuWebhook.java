package ci.masante.payment.service;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.data.redis.core.StringRedisTemplate;
import org.springframework.stereotype.Service;

import java.time.Duration;

/**
 * Anti-rejeu des webhooks carte (§7.3) — CHEMIN RAPIDE Redis. Un événement déjà appliqué est rejeté avant
 * même le round-trip base. La contrainte {@code UNIQUE(psp, evenement_id)} en PostgreSQL reste l'AUTORITÉ
 * (protège si Redis tombe). Le nonce n'est posé qu'APRÈS commit ({@link #marquer}) → un rollback ne « brûle »
 * jamais un événement légitime qui sera rejoué par le PSP.
 */
@Service
public class AntiRejeuWebhook {

    private static final String PREFIXE = "carte:wh:";

    private final StringRedisTemplate redis;
    private final Duration ttl;

    public AntiRejeuWebhook(StringRedisTemplate redis,
                            @Value("${masante.payment.cartes.webhook-nonce-minutes:15}") long nonceMinutes) {
        this.redis = redis;
        this.ttl = Duration.ofMinutes(nonceMinutes);
    }

    /** @return true si l'événement a déjà été vu (nonce présent) → à traiter comme rejeu idempotent. */
    public boolean dejaVu(String psp, String evenementId) {
        return Boolean.TRUE.equals(redis.hasKey(PREFIXE + psp + ":" + evenementId));
    }

    /** Marque l'événement comme traité (posé APRÈS commit de la transaction d'application). */
    public void marquer(String psp, String evenementId) {
        redis.opsForValue().set(PREFIXE + psp + ":" + evenementId, "1", ttl);
    }
}
