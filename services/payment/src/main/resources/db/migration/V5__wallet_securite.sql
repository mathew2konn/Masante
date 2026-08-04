-- P5.3b-1 — Sécurité transactionnelle du Wallet (CDC_06 §6.4).
-- PIN (haché, jamais en clair), limites paramétrables (données), signature d'opération.
-- Frontière : toute vérification (PIN/OTP/limites) est faite backend seul.

-- PIN Wallet : haché BCrypt. Le clair n'est JAMAIS stocké (interdit CDC_00 §4).
-- Verrou temporaire après trop d'échecs (anti brute-force, §6.4).
CREATE TABLE wallet_pins (
    wallet_id       UUID         PRIMARY KEY REFERENCES wallets (id),
    hash            VARCHAR(100) NOT NULL,               -- BCrypt (~60 car.)
    essais_echoues  INT          NOT NULL DEFAULT 0,
    verrou_jusqu_a  TIMESTAMPTZ,                          -- null = non verrouillé
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Limites de montant par opération/jour/mois (§6.4). Valeurs = DONNÉES surchargeant les défauts
-- de configuration. null sur une colonne = « utiliser le défaut de configuration ».
CREATE TABLE wallet_limites (
    wallet_id         UUID        PRIMARY KEY REFERENCES wallets (id),
    plafond_operation BIGINT,
    plafond_jour      BIGINT,
    plafond_mois      BIGINT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Signature numérique de l'opération (§6.4). « Prête à activer » : RSA-SHA256 (clé de dév en
-- mémoire, substitut HSM), réutilise ServiceSignature. null si la signature est désactivée.
ALTER TABLE wallet_operations ADD COLUMN signature VARCHAR(512);
