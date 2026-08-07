-- P5.4a — Cartes bancaires (CDC_06 §5). Paiement SIMULÉ (FT5) ; deux modalités de passerelle
-- (TOKENISEE / REDIRIGEE). FRONTIÈRE PCI : aucune colonne ne contient jamais de PAN, CVV ni piste
-- magnétique — seuls un TOKEN et des métadonnées non sensibles (marque, last4, expiration) fournis
-- PAR la passerelle. Montants = entiers en unité mineure + devise (l'exposant est porté par la devise ;
-- le XOF n'a pas de sous-unité — aucun ×100). Voir ADR-015.
--
-- Décisions Phase 0 (validées) : le service paiement est isolé (Postgres propre, ADR-013) → PAS de FK
-- vers une table `utilisateurs` (inexistante ici) ; l'identité est une RÉFÉRENCE texte posée par la
-- passerelle (`utilisateur_ref`). La table des paiements s'appelle `payments`.

-- 4.1 — Vault de cartes (prêt pour les mandats récurrents §5.4 : network_transaction_id conservé).
CREATE TABLE cartes (
    id                     UUID         PRIMARY KEY,
    utilisateur_ref        VARCHAR(120) NOT NULL,               -- réf posée par la passerelle (pas de FK inter-services)
    psp                    VARCHAR(32)  NOT NULL,               -- identifiant de passerelle
    psp_customer_id        VARCHAR(128),                        -- requis par la plupart des PSP pour la réutilisation
    token                  VARCHAR(255) NOT NULL,               -- token PSP — JAMAIS un PAN
    empreinte              VARCHAR(128),                        -- fingerprint PSP (détection de doublon)
    marque                 VARCHAR(24)  NOT NULL,               -- Visa/Mastercard/… fourni par la passerelle
    last4                  CHAR(4)      NOT NULL,
    exp_mois               SMALLINT     NOT NULL,
    exp_annee              SMALLINT     NOT NULL,
    network_transaction_id VARCHAR(64),                         -- NTID de la transaction initiale (MIT récurrents)
    par_defaut             BOOLEAN      NOT NULL DEFAULT FALSE,
    cree_le                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le                 TIMESTAMPTZ  NOT NULL DEFAULT now(),
    supprime_le            TIMESTAMPTZ,                          -- soft delete
    CONSTRAINT ck_cartes_last4    CHECK (last4 ~ '^[0-9]{4}$'),
    CONSTRAINT ck_cartes_expmois  CHECK (exp_mois BETWEEN 1 AND 12),
    CONSTRAINT ck_cartes_expannee CHECK (exp_annee >= 2024),
    CONSTRAINT uq_cartes_psp_token UNIQUE (psp, token)
);

-- Une seule carte active par empreinte et par utilisateur (les cartes supprimées ne comptent pas).
CREATE UNIQUE INDEX uq_cartes_utilisateur_empreinte
    ON cartes (utilisateur_ref, empreinte) WHERE supprime_le IS NULL;
CREATE INDEX idx_cartes_utilisateur ON cartes (utilisateur_ref);

-- 4.2 — Transaction carte : le cycle 3DS2/autorisation/capture (sous-état carte, mappé vers PaiementStatut).
CREATE TABLE carte_transactions (
    id                     UUID         PRIMARY KEY,
    paiement_id            UUID         NOT NULL REFERENCES payments (id),
    carte_id               UUID         REFERENCES cartes (id),          -- NULL tant que le token n'est pas connu (redirigée)
    psp                    VARCHAR(32)  NOT NULL,
    modalite               VARCHAR(16)  NOT NULL,                        -- TOKENISEE | REDIRIGEE
    ref_passerelle         VARCHAR(128) NOT NULL,
    statut_carte           VARCHAR(32)  NOT NULL,                        -- StatutCarte (borné côté application)
    challenge_ref_hash     VARCHAR(64),                                  -- SHA-256 du challengeRef — JAMAIS la valeur en clair
    challenge_expire_le    TIMESTAMPTZ,
    autorisation_expire_le TIMESTAMPTZ,
    montant                BIGINT       NOT NULL,                        -- unité mineure de la devise
    devise                 CHAR(3)      NOT NULL DEFAULT 'XOF',
    montant_capture        BIGINT       NOT NULL DEFAULT 0,
    montant_rembourse      BIGINT       NOT NULL DEFAULT 0,
    code_refus             VARCHAR(64),
    version                BIGINT       NOT NULL DEFAULT 0,              -- verrou optimiste
    cree_le                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le                 TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_carte_tx_psp_ref     UNIQUE (psp, ref_passerelle),
    -- Relation 1:1 : un paiement carte ⇄ au plus une transaction carte. Verrou anti-double-cycle
    -- (double autorisation/capture sur un même paiement = double débit → structurellement impossible).
    CONSTRAINT uq_carte_tx_paiement    UNIQUE (paiement_id),
    CONSTRAINT ck_carte_tx_rembourse   CHECK (montant_rembourse <= montant_capture),
    CONSTRAINT ck_carte_tx_capture     CHECK (montant_capture <= montant),
    CONSTRAINT ck_carte_tx_modalite    CHECK (modalite IN ('TOKENISEE', 'REDIRIGEE'))
);

-- UNIQUE(paiement_id) fournit déjà l'index sur paiement_id → pas d'index séparé.
CREATE INDEX idx_carte_tx_statut ON carte_transactions (statut_carte);

-- 4.3 — Événements webhook : idempotence AU NIVEAU BASE (un événement PSP traité au plus une fois).
CREATE TABLE carte_evenements_webhook (
    id                 UUID         PRIMARY KEY,
    psp                VARCHAR(32)  NOT NULL,
    evenement_id       VARCHAR(128) NOT NULL,
    type               VARCHAR(64)  NOT NULL,
    recu_le            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    traite_le          TIMESTAMPTZ,
    statut_traitement  VARCHAR(24)  NOT NULL,                    -- RECU | TRAITE | ECART | IGNORE
    charge_utile_masquee JSONB      NOT NULL DEFAULT '{}',       -- masquée : jamais de donnée sensible
    CONSTRAINT uq_carte_webhook_psp_evt UNIQUE (psp, evenement_id)
);

-- 4.4 — Remboursements (partiels autorisés). psp ajouté (nécessaire à la contrainte d'unicité spécifiée).
CREATE TABLE carte_remboursements (
    id                            UUID         PRIMARY KEY,
    carte_transaction_id          UUID         NOT NULL REFERENCES carte_transactions (id),
    psp                           VARCHAR(32)  NOT NULL,
    ref_passerelle_remboursement  VARCHAR(128) NOT NULL,
    montant                       BIGINT       NOT NULL,
    devise                        CHAR(3)      NOT NULL DEFAULT 'XOF',
    statut                        VARCHAR(24)  NOT NULL,          -- DEMANDE | REUSSI | REFUSE
    motif                         VARCHAR(255),
    cree_le                       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le                        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_carte_remb_psp_ref UNIQUE (psp, ref_passerelle_remboursement),
    CONSTRAINT ck_carte_remb_montant CHECK (montant > 0)
);

CREATE INDEX idx_carte_remb_tx ON carte_remboursements (carte_transaction_id);

-- 4.5 — Réconciliation quotidienne carte ↔ PSP (vraie confrontation 2 sources ; source PSP SIMULÉE ici,
-- cf. ADR-014/ADR-015). Écarts SIGNALÉS, jamais corrigés.
CREATE TABLE carte_reconciliations (
    id                     UUID        PRIMARY KEY,
    date_rapport           DATE        NOT NULL,
    psp                    VARCHAR(32) NOT NULL,
    nb_transactions_psp    INT         NOT NULL DEFAULT 0,
    nb_transactions_locales INT        NOT NULL DEFAULT 0,
    montant_psp            BIGINT      NOT NULL DEFAULT 0,
    montant_local          BIGINT      NOT NULL DEFAULT 0,
    nb_ecarts              INT         NOT NULL DEFAULT 0,
    ecarts                 JSONB       NOT NULL DEFAULT '{}',
    genere_le              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_carte_recon_date_psp UNIQUE (date_rapport, psp)
);
