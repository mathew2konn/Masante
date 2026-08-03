-- P5.2b — Avoir / note de crédit (§7.1), versionnage (§7.5), signature (§7.4). Additif.

-- Versionnage : une correction crée une NOUVELLE version (jamais de modification en place).
ALTER TABLE factures ADD COLUMN version_numero    INTEGER NOT NULL DEFAULT 1;
ALTER TABLE factures ADD COLUMN origine_facture_id UUID REFERENCES factures (id);  -- v1 de la lignée
ALTER TABLE factures ADD COLUMN remplacee_par_id   UUID REFERENCES factures (id);  -- version suivante
-- Signature (§7.4) : RSA-SHA256 sur le hash d'intégrité ; clé publique stockée pour vérification.
ALTER TABLE factures ADD COLUMN signature        TEXT;
ALTER TABLE factures ADD COLUMN signature_pubkey TEXT;
ALTER TABLE factures ADD COLUMN signature_algo   VARCHAR(40);

-- Le numéro n'est plus unique seul : (numéro, version) l'est — une lignée partage son numéro.
ALTER TABLE factures DROP CONSTRAINT uq_factures_numero;
ALTER TABLE factures ADD  CONSTRAINT uq_factures_numero_version UNIQUE (numero, version_numero);

CREATE INDEX idx_factures_origine ON factures (origine_facture_id);

-- Avoirs (notes de crédit). Numéro AV-{ETAB}-{exercice}-{seq}. Montant = TTC de la facture d'origine.
CREATE TABLE avoirs (
    id                UUID         PRIMARY KEY,
    numero            VARCHAR(60)  NOT NULL,
    facture_id        UUID         NOT NULL REFERENCES factures (id),
    etablissement_ref VARCHAR(120) NOT NULL,
    exercice          INTEGER      NOT NULL,
    montant           BIGINT       NOT NULL CHECK (montant >= 0),
    motif             VARCHAR(255) NOT NULL,
    hash_integrite    VARCHAR(64)  NOT NULL,
    signature         TEXT,
    signature_pubkey  TEXT,
    signature_algo    VARCHAR(40),
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_avoirs_numero UNIQUE (numero)
);

CREATE INDEX idx_avoirs_facture ON avoirs (facture_id);

-- Compteur de numérotation des avoirs, unique par établissement/exercice.
CREATE TABLE avoir_compteurs (
    id                UUID         PRIMARY KEY,
    etablissement_ref VARCHAR(120) NOT NULL,
    exercice          INTEGER      NOT NULL,
    dernier           BIGINT       NOT NULL DEFAULT 0,
    CONSTRAINT uq_avoir_compteur_etab_exercice UNIQUE (etablissement_ref, exercice)
);
