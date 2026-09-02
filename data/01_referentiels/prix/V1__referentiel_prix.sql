-- =============================================================================
--  MaSanté / IVOIRSANTE — Référentiel des prix des médicaments
--  Migration V1 — schéma « referentiel »
--  PostgreSQL 15+
--
--  Modèle : prix_homologue (référence opposable, versionnée, immuable)
--           prix_pharmacie (relevé optionnel remonté par l'officine)
--
--  Règle métier fondatrice : un prix n'est JAMAIS écrasé. On clôture la ligne
--  (date_fin) et on en insère une nouvelle. L'historique des tarifs doit
--  rester reconstituable à la date de toute ordonnance passée.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- exclusion sur périodes
CREATE EXTENSION IF NOT EXISTS pg_trgm;      -- recherche floue sur libellés

CREATE SCHEMA IF NOT EXISTS referentiel;

-- -----------------------------------------------------------------------------
-- 1. Types
-- -----------------------------------------------------------------------------
CREATE TYPE referentiel.type_source_prix AS ENUM (
    'officiel',           -- arrêté / décision de l'autorité (AIRP)
    'conventionnel_cmu',  -- tarif conventionné CNAM-CMU
    'secteur_public',     -- prix de cession Nouvelle PSP-CI
    'indicatif'           -- agrégateur non officiel (PharmaPrix, etc.)
);

CREATE TYPE referentiel.statut_validation AS ENUM (
    'source_externe_non_validee',  -- statut à l'entrée (convention CDC_09)
    'validee_comite',              -- relue par le comité scientifique
    'rejetee'
);

CREATE TYPE referentiel.origine_releve AS ENUM (
    'officine',      -- saisi par le pharmacien titulaire
    'agent_terrain', -- relevé lors d'une enquête
    'utilisateur'    -- signalement patient (jamais opposable)
);

CREATE TYPE referentiel.statut_releve AS ENUM ('en_attente', 'valide', 'rejete');

-- -----------------------------------------------------------------------------
-- 2. Sources de prix
-- -----------------------------------------------------------------------------
CREATE TABLE referentiel.source_prix (
    id                uuid PRIMARY KEY DEFAULT uuid_generate_v4(),
    code              text NOT NULL UNIQUE,
    libelle           text NOT NULL,
    type_source       referentiel.type_source_prix NOT NULL,
    autorite          text,
    url               text,
    -- true = le prix s'impose juridiquement à toutes les officines
    opposable         boolean NOT NULL DEFAULT false,
    -- 1 = simple indication, 5 = texte réglementaire vérifié
    niveau_confiance  smallint NOT NULL DEFAULT 1
                      CHECK (niveau_confiance BETWEEN 1 AND 5),
    cree_le           timestamptz NOT NULL DEFAULT now()
);

COMMENT ON COLUMN referentiel.source_prix.opposable IS
  'Une source non opposable ne peut jamais être affichée comme « prix officiel » dans l''application.';

-- -----------------------------------------------------------------------------
-- 3. Prix homologué — la référence
-- -----------------------------------------------------------------------------
CREATE TABLE referentiel.prix_homologue (
    id                       uuid PRIMARY KEY DEFAULT uuid_generate_v4(),
    code_prix                text NOT NULL UNIQUE,

    -- Lien vers le référentiel national des médicaments (LNME / registre AIRP).
    -- Nullable tant que le rapprochement DCI n'est pas tranché (CDC_09).
    medicament_id            uuid,
    dci_code                 text,

    panier                   text,        -- HTA, DIABETE, PALUDISME, ...
    libelle_source           text NOT NULL,
    nom_commercial_presume   text,
    dosage_presume           text,
    forme_normalisee         text,
    conditionnement_source   text,
    conditionnement_qte      integer CHECK (conditionnement_qte > 0),

    prix_homologue           numeric(12,2) NOT NULL CHECK (prix_homologue > 0),
    devise                   char(3) NOT NULL DEFAULT 'XOF' CHECK (devise = 'XOF'),

    -- Traçabilité réglementaire : ces trois champs sont obligatoires.
    source_id                uuid NOT NULL REFERENCES referentiel.source_prix(id),
    reference_reglementaire  text NOT NULL,
    date_effet               date NOT NULL,
    date_fin                 date,

    statut_validation        referentiel.statut_validation NOT NULL
                             DEFAULT 'source_externe_non_validee',
    niveau_confiance         smallint NOT NULL DEFAULT 1
                             CHECK (niveau_confiance BETWEEN 1 AND 5),
    -- true = libellé source tronqué (conditionnement absent) : à revérifier
    libelle_incomplet        boolean NOT NULL DEFAULT false,

    valide_par               uuid,
    valide_le                timestamptz,
    cree_le                  timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT chk_periode CHECK (date_fin IS NULL OR date_fin > date_effet),

    -- Clé de dédoublonnage et période de validité
    cle_produit              text GENERATED ALWAYS AS (upper(libelle_source)) STORED,
    periode                  daterange GENERATED ALWAYS AS
                             (daterange(date_effet, date_fin, '[)')) STORED,

    -- Deux prix ne peuvent pas se chevaucher pour un même produit et une même source
    CONSTRAINT ex_prix_sans_chevauchement EXCLUDE USING gist (
        cle_produit WITH =,
        source_id   WITH =,
        periode     WITH &&
    )
);

CREATE INDEX idx_prix_homologue_medicament ON referentiel.prix_homologue (medicament_id);
CREATE INDEX idx_prix_homologue_periode    ON referentiel.prix_homologue USING gist (periode);
CREATE INDEX idx_prix_homologue_libelle    ON referentiel.prix_homologue USING gin (libelle_source gin_trgm_ops);
CREATE INDEX idx_prix_homologue_panier     ON referentiel.prix_homologue (panier);

-- Immuabilité : le montant et la référence réglementaire ne se modifient pas.
CREATE OR REPLACE FUNCTION referentiel.f_prix_immuable() RETURNS trigger AS $$
BEGIN
    IF NEW.prix_homologue IS DISTINCT FROM OLD.prix_homologue
       OR NEW.reference_reglementaire IS DISTINCT FROM OLD.reference_reglementaire
       OR NEW.date_effet IS DISTINCT FROM OLD.date_effet THEN
        RAISE EXCEPTION
          'Prix homologué immuable (%). Clôturez la ligne via date_fin puis insérez le nouveau tarif.',
          OLD.code_prix
          USING ERRCODE = 'restrict_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prix_immuable
    BEFORE UPDATE ON referentiel.prix_homologue
    FOR EACH ROW EXECUTE FUNCTION referentiel.f_prix_immuable();

-- -----------------------------------------------------------------------------
-- 4. Prix relevé en officine — optionnel
-- -----------------------------------------------------------------------------
-- pharmacie_id n'a pas de clé étrangère : les pharmacies vivent dans la base
-- du service Pharmacie (principe « database per service », Tome 3 ch. 4.6).
-- L'intégrité est assurée par l'événement métier, pas par le SGBD.
CREATE TABLE referentiel.prix_pharmacie (
    id                 uuid PRIMARY KEY DEFAULT uuid_generate_v4(),
    pharmacie_id       uuid NOT NULL,
    prix_homologue_id  uuid NOT NULL REFERENCES referentiel.prix_homologue(id),

    prix_releve        numeric(12,2) NOT NULL CHECK (prix_releve > 0),
    devise             char(3) NOT NULL DEFAULT 'XOF',
    disponible         boolean NOT NULL DEFAULT true,

    origine            referentiel.origine_releve NOT NULL,
    statut             referentiel.statut_releve NOT NULL DEFAULT 'en_attente',
    releve_le          timestamptz NOT NULL DEFAULT now(),
    releve_par         uuid,
    commentaire        text,

    -- Un seul relevé courant par pharmacie et par produit
    CONSTRAINT uq_releve_courant UNIQUE (pharmacie_id, prix_homologue_id, releve_le)
);

CREATE INDEX idx_prix_pharmacie_pharmacie ON referentiel.prix_pharmacie (pharmacie_id);
CREATE INDEX idx_prix_pharmacie_produit   ON referentiel.prix_pharmacie (prix_homologue_id);
CREATE INDEX idx_prix_pharmacie_recent    ON referentiel.prix_pharmacie (releve_le DESC);

-- -----------------------------------------------------------------------------
-- 5. Vues de consultation
-- -----------------------------------------------------------------------------

-- 5.1 Prix homologué en vigueur à une date donnée
CREATE OR REPLACE VIEW referentiel.v_prix_en_vigueur AS
SELECT p.*, s.code AS source_code, s.type_source, s.opposable
FROM   referentiel.prix_homologue p
JOIN   referentiel.source_prix s ON s.id = p.source_id
WHERE  p.periode @> CURRENT_DATE;

-- 5.2 Écart entre le prix pratiqué et le prix homologué
CREATE OR REPLACE VIEW referentiel.v_ecart_prix AS
SELECT
    pp.id                AS releve_id,
    pp.pharmacie_id,
    ph.code_prix,
    ph.libelle_source,
    ph.prix_homologue,
    pp.prix_releve,
    pp.releve_le,
    round((pp.prix_releve - ph.prix_homologue), 2)                      AS ecart_fcfa,
    round((pp.prix_releve - ph.prix_homologue) * 100 / ph.prix_homologue, 2) AS ecart_pct,
    -- 2 % couvre l'arrondi de monnaie constaté en officine ; au-delà, on signale
    (abs(pp.prix_releve - ph.prix_homologue) * 100 / ph.prix_homologue) > 2 AS anomalie
FROM referentiel.prix_pharmacie pp
JOIN referentiel.prix_homologue ph ON ph.id = pp.prix_homologue_id
WHERE pp.statut = 'valide';

-- 5.3 Prix à afficher dans l'application
--     Le prix homologué reste la référence ; le prix officine ne s'y substitue
--     que s'il est validé, récent (< 90 jours) et issu de l'officine elle-même.
CREATE OR REPLACE VIEW referentiel.v_prix_affichage AS
SELECT
    ph.id                AS prix_homologue_id,
    ph.code_prix,
    ph.libelle_source,
    ph.panier,
    ph.prix_homologue    AS prix_reference,
    ph.reference_reglementaire,
    ph.date_effet,
    pp.pharmacie_id,
    pp.prix_releve       AS prix_pharmacie,
    COALESCE(pp.prix_releve, ph.prix_homologue) AS prix_affiche,
    CASE
        WHEN pp.prix_releve IS NULL                   THEN 'reference_officielle'
        WHEN pp.prix_releve = ph.prix_homologue       THEN 'confirme_par_officine'
        ELSE                                               'ecart_signale'
    END AS nature_prix
FROM referentiel.prix_homologue ph
LEFT JOIN LATERAL (
    SELECT p.pharmacie_id, p.prix_releve
    FROM   referentiel.prix_pharmacie p
    WHERE  p.prix_homologue_id = ph.id
      AND  p.statut  = 'valide'
      AND  p.origine = 'officine'
      AND  p.releve_le > now() - interval '90 days'
    ORDER  BY p.releve_le DESC
    LIMIT  1
) pp ON true
WHERE ph.periode @> CURRENT_DATE;

COMMENT ON VIEW referentiel.v_prix_affichage IS
  'Source unique pour l''affichage d''un prix côté mobile et web. Ne jamais interroger prix_homologue directement depuis l''UI.';

-- -----------------------------------------------------------------------------
-- 6. Cloisonnement des droits
-- -----------------------------------------------------------------------------
-- L'application ne lit que les vues ; l'écriture des tarifs officiels est
-- réservée au service d'intégration du référentiel.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'masante_app') THEN
        CREATE ROLE masante_app NOLOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'masante_referentiel') THEN
        CREATE ROLE masante_referentiel NOLOGIN;
    END IF;
END $$;

GRANT USAGE ON SCHEMA referentiel TO masante_app, masante_referentiel;
GRANT SELECT ON referentiel.v_prix_affichage, referentiel.v_prix_en_vigueur,
                referentiel.v_ecart_prix TO masante_app;
GRANT INSERT, SELECT ON referentiel.prix_pharmacie TO masante_app;
GRANT ALL ON ALL TABLES IN SCHEMA referentiel TO masante_referentiel;
