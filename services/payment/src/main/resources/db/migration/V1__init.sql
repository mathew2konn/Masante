-- P5.1 — Modèle financier (CDC_06 §4.3). FCFA = entier (BIGINT), pas de décimales.
-- Machine à états stricte (§4.2), idempotence (§9.6), audit immuable append-only (§9.7).

CREATE TABLE payments (
    id                UUID         PRIMARY KEY,
    idempotency_key   VARCHAR(120) NOT NULL,
    correlation_id    VARCHAR(120),
    montant           BIGINT       NOT NULL CHECK (montant > 0),   -- FCFA
    devise            VARCHAR(3)   NOT NULL DEFAULT 'XOF',
    canal             VARCHAR(40)  NOT NULL,                       -- orange_money, wave, carte, wallet…
    objet             VARCHAR(40)  NOT NULL,                       -- RENDEZ_VOUS, ORDONNANCE…
    telephone_masque  VARCHAR(20),
    etablissement_ref VARCHAR(120),
    patient_ref       VARCHAR(120),
    statut            VARCHAR(20)  NOT NULL,                       -- PaiementStatut (source unique)
    provider_ref      VARCHAR(120),
    version           BIGINT       NOT NULL DEFAULT 0,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    confirmed_at      TIMESTAMPTZ,
    -- Anti double-paiement (§9.6) : une clé d'idempotence = au plus un paiement.
    CONSTRAINT uq_payments_idempotency_key UNIQUE (idempotency_key)
);

CREATE INDEX idx_payments_statut       ON payments (statut);
CREATE INDEX idx_payments_patient_ref  ON payments (patient_ref);

-- Historique complet des transitions (§4.2 : toute transition horodatée et persistée).
CREATE TABLE payment_transitions (
    id           UUID         PRIMARY KEY,
    payment_id   UUID         NOT NULL REFERENCES payments (id) ON DELETE CASCADE,
    statut_de    VARCHAR(20),                                     -- NULL = création
    statut_vers  VARCHAR(20)  NOT NULL,
    raison       VARCHAR(255),
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX idx_transitions_payment ON payment_transitions (payment_id);

-- Journal d'audit immuable : append-only à hachage chaîné (§9.7).
-- `sequence` est monotone globale ; `hash` chaîne `previous_hash` → toute altération est détectable.
CREATE TABLE audit_entries (
    id             UUID         PRIMARY KEY,
    sequence       BIGINT       NOT NULL,
    evenement      VARCHAR(60)  NOT NULL,                          -- PaymentInitiated, PaymentConfirmed…
    ref_type       VARCHAR(40)  NOT NULL,                          -- payment, refund…
    ref_id         VARCHAR(120) NOT NULL,
    payload        TEXT         NOT NULL,
    previous_hash  VARCHAR(64)  NOT NULL,
    hash           VARCHAR(64)  NOT NULL,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_audit_sequence UNIQUE (sequence),
    CONSTRAINT uq_audit_hash     UNIQUE (hash)
);

CREATE INDEX idx_audit_ref ON audit_entries (ref_type, ref_id);

-- Ancre GENESIS (sequence 0) : garantit une ligne à verrouiller dès le 1er ajout réel (sérialisation
-- des écritures concurrentes) et fournit le maillon de tête de la chaîne de hachage.
INSERT INTO audit_entries (id, sequence, evenement, ref_type, ref_id, payload, previous_hash, hash)
VALUES (gen_random_uuid(), 0, 'GENESIS', 'system', 'genesis', '{}', 'GENESIS',
        '0000000000000000000000000000000000000000000000000000000000000000');
