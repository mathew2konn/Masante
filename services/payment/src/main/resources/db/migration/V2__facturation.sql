-- P5.2a — Facturation (CDC_06 §7). FCFA = entier (BIGINT). Montants HT/TVA/TTC calculés backend.
-- La TVA et les tarifs ne sont JAMAIS codés en dur : le taux de TVA est une donnée portée par la ligne.

CREATE TABLE factures (
    id                UUID          PRIMARY KEY,
    numero            VARCHAR(60)   NOT NULL,                    -- FCT-{etab}-{exercice}-{seq}
    etablissement_ref VARCHAR(120)  NOT NULL,
    patient_ref       VARCHAR(120),
    exercice          INTEGER       NOT NULL,                    -- année comptable
    devise            VARCHAR(3)    NOT NULL DEFAULT 'XOF',
    sous_total_ht     BIGINT        NOT NULL,
    total_remises     BIGINT        NOT NULL DEFAULT 0,
    total_tva         BIGINT        NOT NULL DEFAULT 0,
    montant_ttc       BIGINT        NOT NULL,
    -- Prise en charge (réutilise le moteur CNAM/assurance) appliquée sur le TTC.
    couverture_type   VARCHAR(40),
    couverture_taux   INTEGER,
    montant_couvert   BIGINT        NOT NULL DEFAULT 0,
    reste_a_payer     BIGINT        NOT NULL,                    -- dû patient après couverture
    montant_regle     BIGINT        NOT NULL DEFAULT 0,          -- cumul des règlements
    statut            VARCHAR(24)   NOT NULL,                    -- FactureStatut (source unique)
    hash_integrite    VARCHAR(64)   NOT NULL,                    -- pour le QR / vérification
    version           BIGINT        NOT NULL DEFAULT 0,
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT uq_factures_numero UNIQUE (numero),
    CONSTRAINT ck_factures_ttc CHECK (montant_couvert + reste_a_payer = montant_ttc)
);

CREATE INDEX idx_factures_etab    ON factures (etablissement_ref);
CREATE INDEX idx_factures_patient ON factures (patient_ref);

CREATE TABLE facture_lignes (
    id             UUID         PRIMARY KEY,
    facture_id     UUID         NOT NULL REFERENCES factures (id) ON DELETE CASCADE,
    ordre          INTEGER      NOT NULL,
    libelle        VARCHAR(255) NOT NULL,
    quantite       INTEGER      NOT NULL CHECK (quantite > 0),
    prix_unitaire  BIGINT       NOT NULL CHECK (prix_unitaire >= 0),
    remise         BIGINT       NOT NULL DEFAULT 0 CHECK (remise >= 0),
    taux_tva       INTEGER      NOT NULL DEFAULT 0 CHECK (taux_tva BETWEEN 0 AND 100),
    montant_ht     BIGINT       NOT NULL,
    montant_tva    BIGINT       NOT NULL,
    montant_ttc    BIGINT       NOT NULL
);

CREATE INDEX idx_facture_lignes_facture ON facture_lignes (facture_id);

-- Compteur de numérotation unique par établissement et par exercice (§7.4).
CREATE TABLE facture_compteurs (
    id                UUID         PRIMARY KEY,
    etablissement_ref VARCHAR(120) NOT NULL,
    exercice          INTEGER      NOT NULL,
    dernier           BIGINT       NOT NULL DEFAULT 0,
    CONSTRAINT uq_compteur_etab_exercice UNIQUE (etablissement_ref, exercice)
);

-- Lien règlement : un paiement peut solder une facture (§7.3 paiement par étapes).
ALTER TABLE payments ADD COLUMN facture_id UUID REFERENCES factures (id);
CREATE INDEX idx_payments_facture ON payments (facture_id);
