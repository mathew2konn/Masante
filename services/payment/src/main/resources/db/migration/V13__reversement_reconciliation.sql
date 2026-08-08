-- P5.5c — Rapprochement à DEUX sources « factures ↔ reversements » (CDC_06 §11, ADR-014 §2, ADR-016 §7).
--
-- VRAI rapprochement, pas un auditeur interne : il confronte deux sous-systèmes maintenus
-- INDÉPENDAMMENT — la FACTURATION (factures PAYEE imputables, remboursements carte REUSSI) et les
-- REVERSEMENTS (lignes de relevé actives). C'est le bras « factures ↔ reversements » du rapprochement
-- quotidien du §11 ; le bras « opérateurs ↔ MASANTÉ » reste différé (aucun relevé opérateur réel, FT5).
--
-- INVARIANTS : lecture SEULE des données financières ; n'écrit QUE son rapport + le journal d'audit.
-- Écarts SIGNALÉS, JAMAIS corrigés (CDC_06 §11). Snapshot au cut-off T (created_at/soldee_a < T).
-- Idempotent : au plus un rapport par journée comptable (UNIQUE(date_rapport)).

CREATE TABLE reversement_reconciliations (
    id                   UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    date_rapport         DATE        NOT NULL,             -- journée comptable rapprochée (UTC)
    cut_off_t            TIMESTAMPTZ NOT NULL,             -- snapshot : n'examine que < T
    grace_jours          INT         NOT NULL DEFAULT 0,   -- délai de grâce (donnée) de la complétude A→B
    grace_cut_off        TIMESTAMPTZ NOT NULL,             -- T − grace : pièce soldée avant = due exigible
    nb_pieces_examinees  INT         NOT NULL DEFAULT 0,   -- source A : pièces dues confrontées
    nb_lignes_examinees  INT         NOT NULL DEFAULT 0,   -- source B : lignes de relevé actives confrontées
    statut               VARCHAR(16) NOT NULL,             -- OK | ECARTS
    nb_ecarts            INT         NOT NULL DEFAULT 0,
    ecarts               JSONB       NOT NULL DEFAULT '[]', -- détail des écarts (PIECE_NON_REVERSEE, …)
    genere_le            TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Idempotence : réexécuter une journée recalcule le même rapport (pas de doublon).
    CONSTRAINT uq_rev_recon_date UNIQUE (date_rapport)
);

CREATE INDEX idx_rev_recon_date ON reversement_reconciliations (date_rapport DESC);

COMMENT ON TABLE reversement_reconciliations IS
    'Rapprochement 2 sources factures↔reversements (P5.5c, CDC_06 §11). Détection seule, jamais de '
    'correction. Bras opérateurs↔MASANTÉ différé (FT5). Voir ADR-014/ADR-016.';
