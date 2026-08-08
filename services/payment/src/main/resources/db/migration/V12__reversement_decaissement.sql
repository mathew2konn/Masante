-- =====================================================================
--  MaSanté — payment-service — V12__reversement_decaissement.sql
--  P5.5b-2 : versement effectif d'un relevé APPROUVÉ (décaissement SIMULÉ,
--  FT5). Machine EN_COURS/EXECUTE/ECHOUE, écriture de DÉCAISSEMENT
--  (compte FRAIS_PASSERELLE), idempotence anti-double-versement, registre
--  local de décaissement (bras local du futur rapprochement 2 sources S11.x).
--  Cible : PostgreSQL 16. Monnaie : XOF entier (BIGINT). Style hybride ciblé
--  (ADR-016) : métier en Java, garde-fous déclaratifs ici, pas de trigger neuf.
--
--  Rien à modifier côté enums/états : V11 autorise déjà EN_COURS/EXECUTE/ECHOUE
--  (ck_rev_statut), DECAISSEMENT (ck_ecr_type) et FRAIS_PASSERELLE (ck_gll_compte).
-- =====================================================================


-- =====================================================================
-- SECTION 1 — REGISTRE LOCAL DE DÉCAISSEMENT
-- ---------------------------------------------------------------------
-- Une ligne par TENTATIVE de versement d'un relevé. C'est le bras LOCAL du
-- rapprochement à 2 sources de S11.x (registre local ⇄ vérité passerelle,
-- miroir de carte_reconciliation) : on y stocke la référence passerelle, le
-- statut, les montants, la clé d'idempotence et le motif d'échec.
--
-- Anti-double-versement (défense en profondeur, garant SGBD) :
--   * uq_decaissement_reussi_par_releve  → ≤ 1 versement EXECUTE par relevé ;
--   * uq_decaissement_en_cours_par_releve → ≤ 1 versement EN_COURS à la fois ;
--   * uq_decaissement_idempotency        → rejeu de la MÊME clé neutralisé.
-- Rejouer après ECHOUE = nouvelle tentative (nouvelle clé) → nouvelle ligne.
-- =====================================================================

CREATE TABLE reversement_decaissement (
    id                   UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    releve_id            UUID         NOT NULL REFERENCES reversement_releves (id),
    destination_id       UUID         NOT NULL REFERENCES reversement_destination (id),
    reference_passerelle VARCHAR(64),                 -- réf opérateur simulée (SIMRV-…) ; NULL tant que non émise
    statut               VARCHAR(16)  NOT NULL,
    montant_net          BIGINT       NOT NULL,        -- somme due à l'établissement (XOF)
    frais                BIGINT       NOT NULL DEFAULT 0, -- frais passerelle rapportés (XOF) — DONNÉE, jamais codés
    devise               CHAR(3)      NOT NULL DEFAULT 'XOF',
    idempotency_key      VARCHAR(120) NOT NULL,
    motif_echec          VARCHAR(255),
    cree_par             VARCHAR(64)  NOT NULL,
    cree_le              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le               TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_dec_statut  CHECK (statut IN ('EN_COURS','EXECUTE','ECHOUE')),
    CONSTRAINT ck_dec_montant CHECK (montant_net > 0 AND frais >= 0),
    CONSTRAINT ck_dec_devise  CHECK (devise = 'XOF')
);

-- ≤ 1 versement RÉUSSI par relevé (l'argent ne part qu'une fois).
CREATE UNIQUE INDEX uq_decaissement_reussi_par_releve
    ON reversement_decaissement (releve_id) WHERE statut = 'EXECUTE';
-- ≤ 1 versement EN_COURS par relevé (une seule tentative en vol).
CREATE UNIQUE INDEX uq_decaissement_en_cours_par_releve
    ON reversement_decaissement (releve_id) WHERE statut = 'EN_COURS';
-- Idempotence : la même clé ne crée jamais deux tentatives (2e barrière après Redis).
CREATE UNIQUE INDEX uq_decaissement_idempotency
    ON reversement_decaissement (idempotency_key);

CREATE INDEX idx_decaissement_releve ON reversement_decaissement (releve_id, cree_le DESC);

COMMENT ON TABLE reversement_decaissement IS
    'Registre local des tentatives de versement (bras local du rapprochement 2 sources S11.x). '
    'La référence de destination en clair n''y figure JAMAIS ; seule reference_passerelle (réf opérateur) '
    'y est stockée. Anti-double-versement par index partiels (EXECUTE/EN_COURS) + idempotency_key unique.';


-- =====================================================================
-- SECTION 2 — GRAND LIVRE : UNE SEULE ÉCRITURE DE DÉCAISSEMENT PAR RELEVÉ
-- ---------------------------------------------------------------------
-- Dernier rempart structurel : l'écriture de DÉCAISSEMENT n'est postée qu'au
-- passage EXECUTE ; l'unicité garantit qu'un double EXECUTE ne peut pas écrire
-- deux écritures (miroir de uq_ecr_constatation_par_releve de V11).
-- =====================================================================

CREATE UNIQUE INDEX uq_ecr_decaissement_par_releve
    ON reversement_ecriture (releve_id) WHERE type_ecriture = 'DECAISSEMENT';


-- =====================================================================
-- SECTION 3 — MOINDRE PRIVILÈGE (append-only déclaratif)
-- ---------------------------------------------------------------------
-- Effectif seulement sous un rôle applicatif ≠ propriétaire des tables
-- (à vérifier en G2). Seule maj_le/statut/reference_passerelle/motif_echec
-- d'une tentative EN_COURS évoluent → on borne l'UPDATE aux colonnes de suivi.
-- =====================================================================
-- REVOKE UPDATE, DELETE ON reversement_decaissement FROM masante_app;
-- GRANT  UPDATE (statut, reference_passerelle, motif_echec, maj_le)
--        ON reversement_decaissement TO masante_app;

-- FIN V12.
