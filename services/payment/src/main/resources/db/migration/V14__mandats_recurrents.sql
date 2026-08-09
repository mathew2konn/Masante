-- P5.4b — Mandats de paiement récurrents (CDC_06 §5.4). PAIEMENT SIMULÉ (FT5) : le débit est un MIT
-- (Merchant-Initiated) via le token du vault + le network_transaction_id conservé (V9). Aucune donnée de
-- carte ici (frontière PCI). Montants = entiers en unité mineure + devise (XOF sans sous-unité). Voir ADR-018.
--
-- Décisions : le service paiement est isolé (Postgres propre, ADR-013) → pas de FK vers `utilisateurs`
-- (référence texte `utilisateur_ref`). L'anti double-prélèvement repose sur UNIQUE(mandat_id, numero_sequence)
-- + la clé d'idempotence déterministe du paiement (mandat:<id>:<seq>) + verrous.

CREATE TABLE mandats (
    id                 UUID         PRIMARY KEY,
    idempotency_key    VARCHAR(120) NOT NULL,
    utilisateur_ref    VARCHAR(120) NOT NULL,
    carte_id           UUID         NOT NULL REFERENCES cartes (id),
    psp                VARCHAR(32)  NOT NULL,                 -- dénormalisé depuis la carte (dispatch OCP)
    objet              VARCHAR(32)  NOT NULL,                 -- ObjetPaiement (tracé, jamais interprété métier)
    libelle            VARCHAR(200),
    montant            BIGINT       NOT NULL,                 -- unité mineure de la devise
    devise             CHAR(3)      NOT NULL DEFAULT 'XOF',
    periodicite        VARCHAR(16)  NOT NULL,                 -- HEBDOMADAIRE | MENSUEL | TRIMESTRIEL | ANNUEL
    date_debut         DATE         NOT NULL,
    date_fin           DATE,
    prochaine_echeance DATE,
    preavis_jours      INT          NOT NULL DEFAULT 3,
    statut             VARCHAR(16)  NOT NULL,                 -- ACTIF | SUSPENDU | ANNULE | EXPIRE
    sequence_courante  INT          NOT NULL DEFAULT 0,
    etablissement_ref  VARCHAR(120),
    patient_ref        VARCHAR(120),
    acteur             VARCHAR(120),
    version            BIGINT       NOT NULL DEFAULT 0,
    cree_le            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    cloture_le         TIMESTAMPTZ,
    CONSTRAINT uq_mandats_idempotency UNIQUE (idempotency_key),
    CONSTRAINT ck_mandats_montant     CHECK (montant > 0),
    CONSTRAINT ck_mandats_preavis     CHECK (preavis_jours >= 0),
    CONSTRAINT ck_mandats_dates       CHECK (date_fin IS NULL OR date_fin >= date_debut)
);

CREATE INDEX idx_mandats_utilisateur ON mandats (utilisateur_ref);
CREATE INDEX idx_mandats_statut_fin  ON mandats (statut, date_fin);

CREATE TABLE mandat_echeances (
    id                   UUID         PRIMARY KEY,
    mandat_id            UUID         NOT NULL REFERENCES mandats (id),
    numero_sequence      INT          NOT NULL,
    date_prevue          DATE         NOT NULL,
    montant              BIGINT       NOT NULL,
    devise               CHAR(3)      NOT NULL DEFAULT 'XOF',
    statut               VARCHAR(16)  NOT NULL,               -- PLANIFIEE | PREAVIS | EXECUTEE | ECHOUEE | SAUTEE
    preavis_le           TIMESTAMPTZ,
    execute_le           TIMESTAMPTZ,
    paiement_id          UUID         REFERENCES payments (id),
    carte_transaction_id UUID         REFERENCES carte_transactions (id),
    code_refus           VARCHAR(64),
    version              BIGINT       NOT NULL DEFAULT 0,
    cree_le              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le               TIMESTAMPTZ  NOT NULL DEFAULT now(),
    -- ANTI DOUBLE-PRÉLÈVEMENT : une seule ligne par (mandat, séquence).
    CONSTRAINT uq_echeance_mandat_sequence UNIQUE (mandat_id, numero_sequence)
);

-- Requête du job d'exécution / de préavis (statut + date).
CREATE INDEX idx_echeances_statut_date ON mandat_echeances (statut, date_prevue);
CREATE INDEX idx_echeances_mandat      ON mandat_echeances (mandat_id);
