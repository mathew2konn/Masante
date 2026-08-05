-- P5.3b-4 — Contrôle d'intégrité financière interne (CDC_06 §6.3 « rapprochement quotidien
-- automatique, alerte en cas d'écart »).
--
-- ATTENTION AU VOCABULAIRE : ce n'est PAS un « rapprochement ». Un rapprochement confronte DEUX
-- sources indépendantes (relevé opérateur ↔ base MASANTÉ). Tant que la passerelle opérateur réelle et
-- les reversements (§11) n'existent pas, il n'y a qu'UNE source (notre base). C'est donc un
-- AUDITEUR D'INTÉGRITÉ INTERNE (cohérence de la base avec elle-même). Le vrai rapprochement à deux
-- sources = incrément S11.x ultérieur (voir ADR-014 : point d'extension documenté).
--
-- INVARIANTS DU CONTRÔLE : lecture SEULE des données financières ; il n'écrit QUE son rapport
-- (controle_runs) et ses écarts (controle_ecarts) + le journal d'audit. Les écarts sont SIGNALÉS,
-- JAMAIS corrigés silencieusement (CDC_06 §11). Idempotent : au plus un verdict par journée comptable.

-- Un run = un contrôle exécuté sur une journée comptable (UTC), à un arrêté T (cut-off).
CREATE TABLE controle_runs (
    id           UUID         PRIMARY KEY,
    journee      DATE         NOT NULL,             -- journée comptable contrôlée (UTC)
    arrete_a     TIMESTAMPTZ  NOT NULL,             -- cut-off T : n'examine QUE created_at < T (snapshot)
    statut       VARCHAR(16)  NOT NULL,             -- OK | ECARTS
    nb_controles INT          NOT NULL DEFAULT 0,   -- nombre de contrôles exécutés
    nb_ecarts    INT          NOT NULL DEFAULT 0,
    duree_ms     BIGINT       NOT NULL DEFAULT 0,
    execute_a    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    -- Idempotence : rejouer le contrôle d'une même journée remplace le verdict (pas de doublon).
    CONSTRAINT uq_controle_run_journee UNIQUE (journee)
);

CREATE INDEX idx_controle_runs_journee ON controle_runs (journee);

-- Un écart détecté. Détection SEULE : aucune colonne « corrigé » — le contrôle ne corrige jamais.
CREATE TABLE controle_ecarts (
    id               UUID         PRIMARY KEY,
    run_id           UUID         NOT NULL REFERENCES controle_runs (id) ON DELETE CASCADE,
    controle         VARCHAR(32)  NOT NULL,          -- DOUBLE_ECRITURE | PAIEMENT_FACTURE | CASHBACK
    type_ecart       VARCHAR(48)  NOT NULL,          -- taxonomie (voir domain/integrity/TypeEcart)
    severite         VARCHAR(16)  NOT NULL,          -- CRITIQUE | MAJEUR
    reference        VARCHAR(180) NOT NULL,          -- l'entité fautive (op id, wallet id, n° facture…)
    montant_attendu  BIGINT,                         -- FCFA (entier) — null si non pertinent
    montant_constate BIGINT,
    details          JSONB        NOT NULL DEFAULT '{}',
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX idx_controle_ecarts_run  ON controle_ecarts (run_id);
CREATE INDEX idx_controle_ecarts_type ON controle_ecarts (type_ecart);
