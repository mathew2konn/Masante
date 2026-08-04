package ci.masante.payment.domain.model;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.CreationTimestamp;
import org.hibernate.annotations.UpdateTimestamp;

import java.time.Instant;
import java.util.UUID;

/**
 * PIN d'un portefeuille (CDC_06 §6.4). Le PIN en clair n'est JAMAIS stocké : seul son haché BCrypt
 * l'est (interdit CDC_00 §4). Après trop d'échecs consécutifs, le PIN est verrouillé temporairement.
 *
 * <p><b>Frontière</b> : cette entité ne décide rien ; le comptage des essais et le verrou sont posés
 * par {@code ServiceSecuriteWallet}.</p>
 */
@Entity
@Table(name = "wallet_pins")
public class WalletPin {

    @Id
    @Column(name = "wallet_id", updatable = false, nullable = false)
    private UUID walletId;

    @Column(name = "hash", nullable = false)
    private String hash;

    @Column(name = "essais_echoues", nullable = false)
    private int essaisEchoues;

    @Column(name = "verrou_jusqu_a")
    private Instant verrouJusquA;

    @CreationTimestamp
    @Column(name = "created_at", nullable = false, updatable = false)
    private Instant createdAt;

    @UpdateTimestamp
    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    protected WalletPin() {
    }

    public WalletPin(UUID walletId, String hash) {
        this.walletId = walletId;
        this.hash = hash;
    }

    public boolean estVerrouille(Instant maintenant) {
        return verrouJusquA != null && maintenant.isBefore(verrouJusquA);
    }

    /** Enregistre un échec ; pose un verrou si le seuil d'essais est atteint. */
    public void enregistrerEchec(int maxEssais, int verrouMinutes, Instant maintenant) {
        this.essaisEchoues++;
        if (this.essaisEchoues >= maxEssais) {
            this.verrouJusquA = maintenant.plusSeconds((long) verrouMinutes * 60);
        }
    }

    /** Succès : remet le compteur à zéro et lève tout verrou. */
    public void reinitialiser() {
        this.essaisEchoues = 0;
        this.verrouJusquA = null;
    }

    public void changerHash(String hash) {
        this.hash = hash;
        reinitialiser();
    }

    public UUID getWalletId() {
        return walletId;
    }

    public String getHash() {
        return hash;
    }

    public int getEssaisEchoues() {
        return essaisEchoues;
    }

    public Instant getVerrouJusquA() {
        return verrouJusquA;
    }
}
