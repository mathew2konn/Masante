-- =====================================================================
--  MaSanté — payment-service — V10__reversements.sql
--  P5.5a : socle reversement, calcul des sommes dues, relevé immuable.
--  Cible : PostgreSQL 16. Monnaie : XOF entier (BIGINT). Jamais de flottant.
--
--  STYLE (ADR-016) : « hybride ciblé ». Le MÉTIER (équation, report, machine
--  à états, commission, hash) vit en Java pur, testable sans base. La base ne
--  porte que des garde-fous DÉCLARATIFS (CHECK, UNIQUE, FK, index partiels).
--  UN SEUL trigger plpgsql dans tout V10 : `factures.soldee_a` — justifié car
--  QUATRE services écrivent `factures` (un stamp centralisé évite 4 bugs).
--  L'immuabilité des relevés/lignes/config = Java (pas de mutateurs) + REVOKE
--  déclaratif (section 7). Aucune autre logique en base : cohérent avec V1→V9.
--
--  INVARIANTS PORTÉS PAR CETTE MIGRATION
--    I1  Une facture (resp. un remboursement) est imputée sur AU PLUS un relevé
--        non annulé — c'est cet index, pas la fenêtre de dates, qui rend le
--        double paiement impossible et permet le rattrapage.
--    I2  Une période close peut être annulée puis recalculée (tentative+1).
--    I3  Somme des lignes = totaux de l'en-tête (vue v_reversement_anomalies).
--    I5  Relevés/lignes immuables hors transitions de statut (Java + REVOKE).
--    I6  Deux taux de commission ne se chevauchent pas (UNIQUE partiel + Java).
--    I7  Chaînage de report : ≤1 successeur vivant par relevé (dépendance
--        inter-relevés respectée par l'annulation — cf. ADR-016 §2).
-- =====================================================================


-- =====================================================================
-- SECTION 0 — factures.soldee_a : ASSIETTE TEMPORELLE IMMUABLE
-- ---------------------------------------------------------------------
-- `updated_at` est pollué : @UpdateTimestamp + 4 services (Paiement, Wallet,
-- Carte, Facturation) le remuent après le solde. Une facture soldée en janvier
-- puis retouchée en mars sortirait de janvier et entrerait dans mars → perte
-- ou double paiement silencieux. On introduit une date de solde écrite une
-- seule fois, jamais modifiable. C'est la SEULE assiette temporelle admise.
-- =====================================================================

ALTER TABLE factures ADD COLUMN IF NOT EXISTS soldee_a TIMESTAMPTZ;

COMMENT ON COLUMN factures.soldee_a IS
    'Instant de passage à PAYEE. Écrit une seule fois, immuable (trigger). '
    'Seule assiette temporelle admise pour le calcul des reversements (ADR-016).';

-- Reprise d'historique APPROXIMATIVE (updated_at) pour les factures déjà PAYEE
-- retouchées après leur solde. Tracé DETTE_TECHNIQUE.md DT-REV-01.
UPDATE factures SET soldee_a = updated_at WHERE statut = 'PAYEE' AND soldee_a IS NULL;

CREATE OR REPLACE FUNCTION trg_facture_soldee_a()
RETURNS TRIGGER AS $$
BEGIN
    -- INSERT : une facture peut naître PAYEE (couverture totale) → stamp immédiat.
    IF TG_OP = 'INSERT' THEN
        IF NEW.statut = 'PAYEE' AND NEW.soldee_a IS NULL THEN
            NEW.soldee_a := now();
        END IF;
        RETURN NEW;
    END IF;
    -- UPDATE : stamp au moment exact du passage à PAYEE.
    IF NEW.statut = 'PAYEE' AND OLD.statut IS DISTINCT FROM 'PAYEE' AND NEW.soldee_a IS NULL THEN
        NEW.soldee_a := now();
    END IF;
    -- Écriture unique : une fois posée, la valeur ne bouge plus (no-op accepté).
    IF OLD.soldee_a IS NOT NULL AND NEW.soldee_a IS DISTINCT FROM OLD.soldee_a THEN
        RAISE EXCEPTION 'facture % : soldee_a est immuable (ancienne=%, nouvelle=%)',
            OLD.id, OLD.soldee_a, NEW.soldee_a USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS factures_soldee_a ON factures;
CREATE TRIGGER factures_soldee_a
    BEFORE INSERT OR UPDATE ON factures
    FOR EACH ROW EXECUTE FUNCTION trg_facture_soldee_a();

CREATE INDEX IF NOT EXISTS idx_factures_soldee_a ON factures (soldee_a) WHERE statut = 'PAYEE';


-- =====================================================================
-- SECTION 0bis — carte_remboursements : ÉTABLISSEMENT DÉNORMALISÉ
-- ---------------------------------------------------------------------
-- Le rattachement remboursement→établissement passait par 3 sauts sur des
-- données mutables (remboursement → carte_transactions → payments) — le même
-- raisonnement qui a fait rejeter updated_at. On FIGE l'établissement sur le
-- remboursement à sa création (posé par ServiceCarte). Date d'imputation d'un
-- remboursement = `cree_le` (immuable ; le remboursement naît REUSSI, cf.
-- ServiceCarte). Backfill via la jointure historique → DT-REV-02.
-- =====================================================================

ALTER TABLE carte_remboursements ADD COLUMN IF NOT EXISTS etablissement_ref VARCHAR(120);

UPDATE carte_remboursements cr
   SET etablissement_ref = p.etablissement_ref
  FROM carte_transactions ct
  JOIN payments p ON p.id = ct.paiement_id
 WHERE cr.carte_transaction_id = ct.id AND cr.etablissement_ref IS NULL;

CREATE INDEX IF NOT EXISTS idx_carte_remb_etab
    ON carte_remboursements (etablissement_ref, cree_le) WHERE statut = 'REUSSI';

COMMENT ON COLUMN carte_remboursements.etablissement_ref IS
    'Établissement figé à la création (dénormalisé). Assiette reversement : '
    'statut=REUSSI ∧ cree_le ∈ fenêtre ∧ non déjà imputé. (ADR-016 §5).';


-- =====================================================================
-- SECTION 1 — CONFIGURATION DES TAUX DE COMMISSION (HISTORISÉE)
-- ---------------------------------------------------------------------
-- Un taux est de l'argent : append-only + temporalisé. etablissement_ref NULL
-- = taux par défaut de la plateforme. Non-chevauchement : (a) UNIQUE partiel
-- « au plus un taux ouvert par périmètre » (sérialise aussi la concurrence) ;
-- (b) non-recouvrement temporel + append-only vérifiés en Java
-- (ServiceCommissionConfig). PAS DE SEED : commission_config_id étant NOT NULL,
-- l'absence de config fait ÉCHOUER le calcul bruyamment (panne sûre) — jamais
-- de 0 % silencieux (ADR-016 §10).
-- =====================================================================

CREATE TABLE reversement_commission_config (
    id                  UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    etablissement_ref   VARCHAR(120),
    taux_bps            INTEGER      NOT NULL,
    valide_du           TIMESTAMPTZ  NOT NULL,
    valide_au           TIMESTAMPTZ,
    motif               VARCHAR(255) NOT NULL,
    remplace_config_id  UUID         REFERENCES reversement_commission_config (id),
    cree_par            VARCHAR(64)  NOT NULL,
    cree_a              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT ck_cfg_taux    CHECK (taux_bps BETWEEN 0 AND 10000),
    CONSTRAINT ck_cfg_periode CHECK (valide_au IS NULL OR valide_au > valide_du)
);

-- I6 (a) : au plus UN taux ouvert par périmètre.
CREATE UNIQUE INDEX uq_cfg_un_seul_taux_ouvert
    ON reversement_commission_config ((COALESCE(etablissement_ref, '*'))) WHERE valide_au IS NULL;

COMMENT ON TABLE reversement_commission_config IS
    'Taux de commission historisés (bps entiers ; 250 = 2,50 %). Append-only + '
    'non-chevauchement vérifiés en Java (ServiceCommissionConfig).';


-- =====================================================================
-- SECTION 2 — COMPTEUR DE NUMÉROTATION (SÉQUENCE SANS TROU)
-- ---------------------------------------------------------------------
-- Comme facture_compteurs : incrément sous SELECT ... FOR UPDATE (Java). Le
-- verrou de cette ligne est pris AVANT la lecture de l'assiette : il sérialise
-- calcul + numérotation + chaînage de report (ADR-016 §4, ordre de verrou).
-- =====================================================================

CREATE TABLE reversement_compteur (
    id                UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    etablissement_ref VARCHAR(120) NOT NULL,
    exercice          INTEGER     NOT NULL,
    dernier           BIGINT      NOT NULL DEFAULT 0,
    maj_a             TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_rev_compteur_etab_exercice UNIQUE (etablissement_ref, exercice),
    CONSTRAINT ck_rev_compteur_positif CHECK (dernier >= 0)
);


-- =====================================================================
-- SECTION 3 — RELEVÉS DE REVERSEMENT (EN-TÊTE)
-- =====================================================================

CREATE TABLE reversement_releves (
    id                      UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    numero                  VARCHAR(60)  NOT NULL,
    etablissement_ref       VARCHAR(120) NOT NULL,
    exercice                INTEGER      NOT NULL,

    -- Fenêtre semi-ouverte [debut, fin) ; instants absolus.
    periode_debut           TIMESTAMPTZ  NOT NULL,
    periode_fin             TIMESTAMPTZ  NOT NULL,
    cut_off_t               TIMESTAMPTZ  NOT NULL,
    tentative               INTEGER      NOT NULL DEFAULT 1,
    devise                  CHAR(3)      NOT NULL DEFAULT 'XOF',

    -- Agrégats (XOF entiers)
    montant_brut_du         BIGINT       NOT NULL,
    taux_commission_bps     INTEGER      NOT NULL,
    commission_config_id    UUID         NOT NULL REFERENCES reversement_commission_config (id),
    montant_commission      BIGINT       NOT NULL,
    montant_rembourse       BIGINT       NOT NULL DEFAULT 0,
    report_anterieur        BIGINT       NOT NULL DEFAULT 0,   -- ≤ 0 : dette héritée
    montant_net_a_reverser  BIGINT       NOT NULL,             -- ≥ 0
    solde_reporte           BIGINT       NOT NULL DEFAULT 0,   -- ≤ 0 : reporté au suivant

    statut                  VARCHAR(16)  NOT NULL DEFAULT 'CALCULE',

    -- I7 : chaînage explicite du report (remplace la « requête par date »
    -- ambiguë). report_anterieur = solde_reporte du relevé pointé.
    releve_precedent_id     UUID         REFERENCES reversement_releves (id),

    -- Empreinte de ligne (Java). L'inaltérabilité de SÉRIE (disparition d'un
    -- relevé) est couverte par le journal global chaîné audit_entries (§9.7 ;
    -- previous_hash → hash), documenté ADR-016 §6 (loi 2013-450).
    hash_integrite          CHAR(64)     NOT NULL,

    calcule_par             VARCHAR(64)  NOT NULL,
    calcule_a               TIMESTAMPTZ  NOT NULL DEFAULT now(),
    approuve_par            VARCHAR(64),
    approuve_a              TIMESTAMPTZ,
    annule_par              VARCHAR(64),
    annule_a                TIMESTAMPTZ,
    motif_annulation        VARCHAR(255),

    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    version                 BIGINT       NOT NULL DEFAULT 0,

    CONSTRAINT ck_rev_devise     CHECK (devise = 'XOF'),
    CONSTRAINT ck_rev_fenetre    CHECK (periode_debut < periode_fin),
    -- cut-off ≥ fin de période (marge MVCC pour la visibilité des écritures).
    CONSTRAINT ck_rev_cutoff     CHECK (cut_off_t >= periode_fin),
    CONSTRAINT ck_rev_tentative  CHECK (tentative >= 1),
    CONSTRAINT ck_rev_taux       CHECK (taux_commission_bps BETWEEN 0 AND 10000),
    CONSTRAINT ck_rev_statut     CHECK (statut IN ('CALCULE','APPROUVE','EN_COURS','EXECUTE','ECHOUE','ANNULE')),
    CONSTRAINT ck_rev_signes     CHECK (montant_brut_du >= 0 AND montant_commission >= 0
                                    AND montant_rembourse >= 0 AND montant_net_a_reverser >= 0
                                    AND report_anterieur <= 0 AND solde_reporte <= 0),
    -- Équation du reversement (I3, en-tête) : la part positive va au net, la
    -- part négative au report. net + solde_reporte = brut - com - remb + report.
    CONSTRAINT ck_rev_equation   CHECK (montant_net_a_reverser + solde_reporte
                                    = montant_brut_du - montant_commission
                                      - montant_rembourse + report_anterieur),
    CONSTRAINT ck_rev_exclusivite CHECK (montant_net_a_reverser = 0 OR solde_reporte = 0),
    -- Cohérence du workflow (quatre-yeux + destination = P5.5b/V11).
    CONSTRAINT ck_rev_approbation CHECK ((approuve_par IS NULL) = (approuve_a IS NULL)),
    CONSTRAINT ck_rev_annulation  CHECK ((annule_par IS NULL) = (annule_a IS NULL)
                                    AND (annule_par IS NULL OR motif_annulation IS NOT NULL))
);

COMMENT ON TABLE reversement_releves IS
    'Relevé de reversement immuable (Java + REVOKE). Seuls statut et champs de '
    'workflow évoluent. Décaissement / grand livre / destination = P5.5b (V11).';
COMMENT ON COLUMN reversement_releves.solde_reporte IS
    'Négatif ou nul. Remboursements > encaissements : rien n''est décaissé, la '
    'dette est reportée sur le relevé suivant (report_anterieur).';

-- I2 : unicité de période uniquement parmi les relevés vivants → un ANNULE
-- libère la période, rendant le recalcul (tentative+1) possible.
CREATE UNIQUE INDEX uq_releve_periode_active
    ON reversement_releves (etablissement_ref, periode_debut, periode_fin) WHERE statut <> 'ANNULE';

CREATE UNIQUE INDEX uq_releve_numero
    ON reversement_releves (etablissement_ref, exercice, numero);

-- I7 : ≤1 successeur vivant par relevé (l'annulation ne peut porter que sur le
-- dernier maillon vivant — contrôle Java rendu vérifiable par ce chaînage).
CREATE UNIQUE INDEX uq_releve_successeur_vivant
    ON reversement_releves (releve_precedent_id) WHERE releve_precedent_id IS NOT NULL AND statut <> 'ANNULE';

CREATE INDEX idx_releve_etab_statut ON reversement_releves (etablissement_ref, statut, periode_debut DESC);
CREATE INDEX idx_releve_exercice    ON reversement_releves (exercice, etablissement_ref);
CREATE INDEX idx_releve_chaine      ON reversement_releves (etablissement_ref, calcule_a DESC);


-- =====================================================================
-- SECTION 4 — LIGNES DE RELEVÉ (SNAPSHOT PAR PIÈCE)
-- ---------------------------------------------------------------------
-- Deux natures : FACTURE (encaissement commissionné) et REMBOURSEMENT (décote,
-- sans commission). La distinction est INDISPENSABLE : un remboursement porte
-- souvent sur une facture soldée en période antérieure, DÉJÀ imputée (I1
-- interdirait de recréer une ligne FACTURE) — sans ligne propre, le
-- montant_rembourse de l'en-tête serait injustifiable pièce à pièce.
-- =====================================================================

CREATE TABLE reversement_releve_lignes (
    id                        UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    releve_id                 UUID         NOT NULL REFERENCES reversement_releves (id) ON DELETE RESTRICT,
    type_ligne                VARCHAR(16)  NOT NULL DEFAULT 'FACTURE',
    facture_id                UUID,          -- requis si FACTURE
    remboursement_id          UUID,          -- requis si REMBOURSEMENT
    piece_reference           VARCHAR(60)  NOT NULL,   -- numéro de facture ou réf. remboursement
    piece_datee_a             TIMESTAMPTZ  NOT NULL,   -- factures.soldee_a ou carte_remboursements.cree_le
    montant_regle_impute      BIGINT       NOT NULL DEFAULT 0,
    montant_commission_ligne  BIGINT       NOT NULL DEFAULT 0,
    montant_rembourse_impute  BIGINT       NOT NULL DEFAULT 0,
    montant_net_ligne         BIGINT       NOT NULL,
    -- Seule colonne mutable (basculée en Java à l'annulation du relevé) :
    -- support des index partiels I1. Autorisée en UPDATE par la section 7.
    releve_actif              BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at                TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT ck_ligne_type   CHECK (type_ligne IN ('FACTURE','REMBOURSEMENT')),
    CONSTRAINT ck_ligne_signes CHECK (montant_regle_impute >= 0 AND montant_commission_ligne >= 0
                                  AND montant_rembourse_impute >= 0),
    CONSTRAINT ck_ligne_equation CHECK (montant_net_ligne
                                  = montant_regle_impute - montant_commission_ligne - montant_rembourse_impute),
    -- Une ligne FACTURE ne porte pas de remboursement, et réciproquement.
    CONSTRAINT ck_ligne_coherence_type CHECK (
        (type_ligne = 'FACTURE' AND facture_id IS NOT NULL AND remboursement_id IS NULL
            AND montant_rembourse_impute = 0)
     OR (type_ligne = 'REMBOURSEMENT' AND remboursement_id IS NOT NULL AND facture_id IS NULL
            AND montant_regle_impute = 0 AND montant_commission_ligne = 0))
);

COMMENT ON TABLE reversement_releve_lignes IS
    'Snapshot immuable, une ligne par pièce imputée. Append-only (Java + REVOKE) '
    ': seul releve_actif est modifiable, à l''annulation du relevé.';

-- I1 — invariant central : une pièce sur AU PLUS un relevé vivant.
CREATE UNIQUE INDEX uq_ligne_facture_imputee_une_fois
    ON reversement_releve_lignes (facture_id) WHERE releve_actif AND type_ligne = 'FACTURE';
CREATE UNIQUE INDEX uq_ligne_remboursement_impute_une_fois
    ON reversement_releve_lignes (remboursement_id) WHERE releve_actif AND type_ligne = 'REMBOURSEMENT';

CREATE INDEX idx_ligne_releve  ON reversement_releve_lignes (releve_id, type_ligne);
CREATE INDEX idx_ligne_facture ON reversement_releve_lignes (facture_id) WHERE facture_id IS NOT NULL;


-- =====================================================================
-- SECTION 5 — VUE DE CONTRÔLE (I3) : réconciliation en-tête / lignes
-- ---------------------------------------------------------------------
-- Doit toujours retourner 0 ligne. Sondée en G5, exposable en métrique.
-- =====================================================================

CREATE VIEW v_reversement_anomalies AS
SELECT r.id, r.numero, r.etablissement_ref,
       r.montant_brut_du    AS entete_brut,       COALESCE(l.somme_regle, 0)      AS lignes_brut,
       r.montant_commission AS entete_commission, COALESCE(l.somme_commission, 0) AS lignes_commission,
       r.montant_rembourse  AS entete_rembourse,  COALESCE(l.somme_rembourse, 0)  AS lignes_rembourse
  FROM reversement_releves r
  LEFT JOIN (
        SELECT releve_id,
               SUM(montant_regle_impute)     AS somme_regle,
               SUM(montant_commission_ligne) AS somme_commission,
               SUM(montant_rembourse_impute) AS somme_rembourse
          FROM reversement_releve_lignes
         GROUP BY releve_id
  ) l ON l.releve_id = r.id
 WHERE r.statut <> 'ANNULE'
   AND (r.montant_brut_du    <> COALESCE(l.somme_regle, 0)
     OR r.montant_commission <> COALESCE(l.somme_commission, 0)
     OR r.montant_rembourse  <> COALESCE(l.somme_rembourse, 0));

COMMENT ON VIEW v_reversement_anomalies IS
    'Doit toujours être vide. Toute ligne = écart en-tête / lignes → blocage G5.';


-- =====================================================================
-- SECTION 6 — GRAND LIVRE + DESTINATION : POSÉS EN V11 (P5.5b)
-- ---------------------------------------------------------------------
-- Volontairement absents. On ne pose pas un grand livre incomplet : les
-- écritures en partie double naissent AU DÉCAISSEMENT (P5.5b). Idem destination
-- de paiement chiffrée + quatre-yeux (figés à l'approbation, quand on exécute
-- réellement). Voir ADR-016 §7 et le plan P5.5b.
-- =====================================================================


-- =====================================================================
-- SECTION 7 — MOINDRE PRIVILÈGE (immuabilité déclarative, sans plpgsql)
-- ---------------------------------------------------------------------
-- Ferme les tables les plus sensibles à l'écriture non autorisée. @Version
-- protège des écritures perdues, pas des @Modifying non autorisés ; ces REVOKE
-- le font, sans double autorité ni trigger. HONNÊTETÉ : sans effet quand le
-- service tourne en PROPRIÉTAIRE de la base (cas en dev, rôle `payment`) ;
-- effectif en prod sous un rôle applicatif à moindre privilège → « prêt à
-- activer ». Adapter le nom du rôle (`masante_app`) avant activation.
-- =====================================================================
-- REVOKE UPDATE, DELETE ON reversement_releve_lignes FROM masante_app;
-- GRANT  UPDATE (releve_actif) ON reversement_releve_lignes TO masante_app;
-- REVOKE DELETE ON reversement_releves FROM masante_app;
-- REVOKE UPDATE, DELETE ON reversement_commission_config FROM masante_app;
-- GRANT  UPDATE (valide_au) ON reversement_commission_config TO masante_app;

-- FIN V10.
