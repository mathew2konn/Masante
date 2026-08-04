-- P5.3b-2 — Détection de fraude par RÈGLES (déterministes) + gel sur suspicion (CDC_06 §6.4).
-- La détection par IA (vélocité avancée, géoloc, multi-comptes) reste au futur fraud-detection-service
-- (CDC_05). Ici : règles pures, seuils = données, gel protecteur/réversible avec TTL d'auto-dégel.

-- Gel temporaire : instant d'expiration du gel posé par la détection de fraude (TTL).
-- null = non gelé, ou gel manuel indéfini (admin).
ALTER TABLE wallets ADD COLUMN gel_jusqu_a TIMESTAMPTZ;

-- Alertes de suspicion. Aucune opération n'est créée quand on bloque → l'alerte référence le wallet
-- et le montant TENTÉ. motifs + parametres en JSONB (snapshot rejouable du score).
CREATE TABLE fraud_alertes (
    id            UUID         PRIMARY KEY,
    wallet_id     UUID         NOT NULL REFERENCES wallets (id),
    score         INT          NOT NULL,
    palier        VARCHAR(16)  NOT NULL,      -- ALERTE, CHALLENGE, GEL
    motifs        JSONB        NOT NULL,      -- tableau de codes de motifs
    parametres    JSONB        NOT NULL,      -- snapshot des seuils/poids utilisés
    montant_tente BIGINT       NOT NULL,
    statut        VARCHAR(16)  NOT NULL,      -- OUVERTE, REVUE
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    revue_at      TIMESTAMPTZ,
    revue_par     VARCHAR(120)
);
CREATE INDEX idx_fraud_alertes_wallet ON fraud_alertes (wallet_id, statut);
CREATE INDEX idx_fraud_alertes_statut ON fraud_alertes (statut, created_at);

-- Index de PERFORMANCE : la détection lit ces axes à CHAQUE opération sortante. Sans eux, la latence
-- de paiement se dégrade quand l'historique grossit (exigence de robustesse).
CREATE INDEX idx_wallet_ops_source_date ON wallet_operations (source_wallet_id, created_at);
CREATE INDEX idx_audit_ref_evt_date     ON audit_entries (ref_id, evenement, created_at);
