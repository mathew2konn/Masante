-- =====================================================================
--  MaSanté — payment-service — V11__reversement_destinations_constatation.sql
--  P5.5b-1 : registre chiffré des destinations, quatre-yeux, écriture de
--  CONSTATATION (grand livre en-tête + lignes). AUCUN décaissement (→ P5.5b-2).
--  Cible : PostgreSQL 16. Monnaie : XOF entier (BIGINT). Style hybride ciblé
--  (ADR-016) : métier en Java, garde-fous déclaratifs ici, pas de trigger neuf.
-- =====================================================================


-- =====================================================================
-- SECTION 1 — RELÂCHE DE LA CONVENTION DE SIGNE (report/solde libres)
-- ---------------------------------------------------------------------
-- V10 imposait report_anterieur ≤ 0 ET solde_reporte ≤ 0 : cela interdit le
-- report POSITIF (solde dû à l'établissement mais sous le seuil de versement
-- MoMo → à accumuler), dont b-2 aura besoin. L'équilibre est déjà garanti par
-- ck_rev_equation (V10) ; le modèle d'écriture signé (ReglesEcritureReversement)
-- donne Σ=0 pour TOUT signe. On ne garde donc que les bornes toujours vraies.
-- =====================================================================

ALTER TABLE reversement_releves DROP CONSTRAINT ck_rev_signes;
ALTER TABLE reversement_releves ADD CONSTRAINT ck_rev_signes_positifs
    CHECK (montant_brut_du >= 0 AND montant_commission >= 0
       AND montant_rembourse >= 0 AND montant_net_a_reverser >= 0);

-- Nouvel état terminal : l'approbateur peut REFUSER (distinct d'ANNULE).
ALTER TABLE reversement_releves DROP CONSTRAINT ck_rev_statut;
ALTER TABLE reversement_releves ADD CONSTRAINT ck_rev_statut
    CHECK (statut IN ('CALCULE','APPROUVE','EN_COURS','EXECUTE','ECHOUE','ANNULE','REJETE'));


-- =====================================================================
-- SECTION 2 — REGISTRE DES DESTINATIONS DE REVERSEMENT (CHIFFRÉ)
-- ---------------------------------------------------------------------
-- Une destination par établissement (compte MoMo/IBAN). Historisé, append-only
-- (Java + REVOKE), comme la config commission. JAMAIS de MSISDN/IBAN en clair :
--   ref_chiffree = AES-256-GCM (nonce 12 o stocké, AAD = etablissement_ref‖id,
--                  cle_version) ;
--   empreinte    = HMAC-SHA256 poivré (empreinte_version) — comparaison sans
--                  déchiffrement, NON inversible par force brute (≠ SHA nu).
-- etablissement_ref = référence MOLLE (les établissements vivent dans le cœur
-- Laravel, hors base paiement — ADR-013) : pas de FK possible.
-- =====================================================================

CREATE TABLE reversement_destination (
    id                  UUID         PRIMARY KEY,   -- généré côté appli (nécessaire à l'AAD)
    etablissement_ref   VARCHAR(120) NOT NULL,
    type                VARCHAR(24)  NOT NULL,
    ref_chiffree        BYTEA        NOT NULL,
    nonce               BYTEA        NOT NULL,       -- 12 o, aléatoire, par chiffrement
    cle_version         SMALLINT     NOT NULL,
    empreinte           CHAR(64)     NOT NULL,       -- HMAC-SHA256 hex (poivré)
    empreinte_version   SMALLINT     NOT NULL,
    libelle             VARCHAR(120) NOT NULL,       -- opérateur + 8 hex d'empreinte, aucun chiffre du compte
    valide_du           TIMESTAMPTZ  NOT NULL,
    valide_au           TIMESTAMPTZ,
    motif               VARCHAR(255) NOT NULL,
    remplace_id         UUID         REFERENCES reversement_destination (id),
    cree_par            VARCHAR(64)  NOT NULL,
    cree_a              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_dest_type    CHECK (type IN ('MOBILE_MONEY','VIREMENT_BANCAIRE')),
    CONSTRAINT ck_dest_periode CHECK (valide_au IS NULL OR valide_au > valide_du)
);

-- Une seule destination active par établissement (vrai garant sous concurrence ;
-- le verrou consultatif applicatif sérialise en plus les écrivains).
CREATE UNIQUE INDEX uq_dest_active_par_etab
    ON reversement_destination (etablissement_ref) WHERE valide_au IS NULL;
CREATE INDEX idx_dest_etab ON reversement_destination (etablissement_ref, valide_du DESC);

COMMENT ON TABLE reversement_destination IS
    'Destinations de reversement chiffrées, une active par établissement. '
    'ref_chiffree n''est JAMAIS retournée par une API ; déchiffrement = chemin de '
    'versement (P5.5b-2) uniquement. Append-only (Java + REVOKE §5).';


-- =====================================================================
-- SECTION 3 — SNAPSHOTS DESTINATION SUR LE RELEVÉ + QUATRE-YEUX
-- ---------------------------------------------------------------------
-- empreinte_calcul : figée AU CALCUL (terme gauche du contrôle anti-substitution
-- de destination). empreinte/destination_id/figee_a : figés À L'APPROBATION.
-- =====================================================================

ALTER TABLE reversement_releves
    ADD COLUMN destination_id              UUID REFERENCES reversement_destination (id),
    ADD COLUMN destination_empreinte       CHAR(64),
    ADD COLUMN destination_empreinte_calcul CHAR(64),
    ADD COLUMN destination_figee_a         TIMESTAMPTZ;

-- Quatre-yeux (moitié exprimable en base) : l'approbateur ≠ le calculateur.
-- L'autre moitié (approuve_par ≠ destination.cree_par) est inter-tables →
-- vérifiée en Java + requête d'invariant G2.
ALTER TABLE reversement_releves ADD CONSTRAINT ck_rev_quatre_yeux
    CHECK (approuve_par IS NULL OR approuve_par <> calcule_par);

-- Approbation ⇒ destination figée (id + empreinte + horodatage).
ALTER TABLE reversement_releves ADD CONSTRAINT ck_rev_destination_figee
    CHECK (approuve_a IS NULL
           OR (destination_id IS NOT NULL AND destination_empreinte IS NOT NULL
               AND destination_figee_a IS NOT NULL));


-- =====================================================================
-- SECTION 4 — GRAND LIVRE : EN-TÊTE (ÉCRITURE) + LIGNES
-- ---------------------------------------------------------------------
-- Scindé en-tête/lignes : releve_id/type/date vivent sur l'en-tête (où
-- l'unicité « une constatation par relevé » a un sens), les jambes sur les
-- lignes. Équilibre Σdébit=Σcrédit par écriture garanti en Java
-- (ReglesEcritureReversement) ; balayage global en V11_verification. Append-only.
-- Contre-passation = écriture EXTOURNE inverse référençant l'originale.
-- =====================================================================

CREATE TABLE reversement_ecriture (
    ecriture_id           UUID        PRIMARY KEY,   -- généré côté appli
    releve_id             UUID        NOT NULL REFERENCES reversement_releves (id),
    type_ecriture         VARCHAR(16) NOT NULL,
    date_comptable        DATE        NOT NULL,       -- Africa/Abidjan (UTC+0)
    ecriture_extournee_id UUID        REFERENCES reversement_ecriture (ecriture_id),
    cree_par              VARCHAR(64) NOT NULL,
    cree_le               TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_ecr_type CHECK (type_ecriture IN ('CONSTATATION','DECAISSEMENT','EXTOURNE')),
    -- EXTOURNE ⇔ référence une écriture ; les autres n'en référencent pas.
    CONSTRAINT ck_ecr_extourne CHECK ((type_ecriture = 'EXTOURNE') = (ecriture_extournee_id IS NOT NULL))
);

-- Une seule CONSTATATION vivante par relevé (chaque tentative = un releve_id
-- distinct → la correction par recalcul n'est jamais bloquée).
CREATE UNIQUE INDEX uq_ecr_constatation_par_releve
    ON reversement_ecriture (releve_id) WHERE type_ecriture = 'CONSTATATION';
-- Une écriture ne s'extourne qu'une fois.
CREATE UNIQUE INDEX uq_ecr_extournee_une_fois
    ON reversement_ecriture (ecriture_extournee_id) WHERE ecriture_extournee_id IS NOT NULL;
CREATE INDEX idx_ecr_releve ON reversement_ecriture (releve_id);

CREATE TABLE reversement_grand_livre_ligne (
    id            UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    ecriture_id   UUID         NOT NULL REFERENCES reversement_ecriture (ecriture_id),
    sequence      SMALLINT     NOT NULL,
    compte        VARCHAR(32)  NOT NULL,
    sens          VARCHAR(6)   NOT NULL,
    montant       BIGINT       NOT NULL,
    devise        CHAR(3)      NOT NULL DEFAULT 'XOF',
    libelle       VARCHAR(255) NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_gll_sens    CHECK (sens IN ('DEBIT','CREDIT')),
    CONSTRAINT ck_gll_montant CHECK (montant > 0),
    CONSTRAINT ck_gll_devise  CHECK (devise = 'XOF'),
    CONSTRAINT ck_gll_compte  CHECK (compte IN (
        'TRESORERIE', 'COMMISSION', 'A_REVERSER', 'REMBOURSEMENT',
        'CREANCE_ETABLISSEMENT',
        'FRAIS_PASSERELLE')),   -- réservé P5.5b-2 (frais de décaissement)
    CONSTRAINT uq_gll_ecriture_sequence UNIQUE (ecriture_id, sequence)
);

CREATE INDEX idx_gll_ecriture ON reversement_grand_livre_ligne (ecriture_id);
CREATE INDEX idx_gll_compte   ON reversement_grand_livre_ligne (compte);

COMMENT ON TABLE reversement_grand_livre_ligne IS
    'Jambes du grand livre reversement (partie double). Σdébit=Σcrédit par '
    'ecriture_id (Java + balayage V11_verification). Append-only ; correction = '
    'écriture EXTOURNE, jamais UPDATE/DELETE.';


-- =====================================================================
-- SECTION 5 — MOINDRE PRIVILÈGE (append-only déclaratif)
-- ---------------------------------------------------------------------
-- Effectif seulement sous un rôle applicatif ≠ propriétaire des tables
-- (à vérifier en G2). Adapter `masante_app`.
-- =====================================================================
-- REVOKE UPDATE, DELETE ON reversement_destination        FROM masante_app;
-- GRANT  UPDATE (valide_au) ON reversement_destination    TO   masante_app;
-- REVOKE UPDATE, DELETE ON reversement_ecriture           FROM masante_app;
-- REVOKE UPDATE, DELETE ON reversement_grand_livre_ligne  FROM masante_app;

-- FIN V11.
