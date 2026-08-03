-- P5.3a — Wallet MASANTÉ (CDC_06 §6). Comptabilité en DOUBLE ÉCRITURE obligatoire.
-- Le solde n'est JAMAIS stocké : il est la somme des écritures (§6.3). FCFA = entier (BIGINT).

CREATE TABLE wallets (
    id          UUID         PRIMARY KEY,
    owner_ref   VARCHAR(120) NOT NULL,
    owner_type  VARCHAR(20)  NOT NULL,                 -- PATIENT, ETABLISSEMENT, SYSTEME
    devise      VARCHAR(3)   NOT NULL DEFAULT 'XOF',
    statut      VARCHAR(16)  NOT NULL,                 -- WalletStatut (source unique)
    version     BIGINT       NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_wallet_owner UNIQUE (owner_ref, owner_type, devise)
);

-- Une opération regroupe les DEUX écritures ; sa clé d'idempotence garantit l'unicité (§9.6).
CREATE TABLE wallet_operations (
    id               UUID         PRIMARY KEY,
    idempotency_key  VARCHAR(120) NOT NULL,
    type             VARCHAR(24)  NOT NULL,            -- CREDIT, DEBIT, TRANSFERT, PAIEMENT_FACTURE
    montant          BIGINT       NOT NULL CHECK (montant > 0),
    source_wallet_id UUID REFERENCES wallets (id),
    dest_wallet_id   UUID REFERENCES wallets (id),
    reference        VARCHAR(120),
    libelle          VARCHAR(255),
    facture_id       UUID,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_wallet_op_idempotency UNIQUE (idempotency_key)
);

-- Grand livre immuable, append-only : deux écritures par opération, somme = 0 (double écriture).
-- montant signé : +crédit / −débit. Le solde d'un wallet = SUM(montant) de ses écritures.
CREATE TABLE wallet_entries (
    id           UUID        PRIMARY KEY,
    operation_id UUID        NOT NULL REFERENCES wallet_operations (id),
    wallet_id    UUID        NOT NULL REFERENCES wallets (id),
    montant      BIGINT      NOT NULL,                 -- signé
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_wallet_entries_wallet    ON wallet_entries (wallet_id);
CREATE INDEX idx_wallet_entries_operation ON wallet_entries (operation_id);
