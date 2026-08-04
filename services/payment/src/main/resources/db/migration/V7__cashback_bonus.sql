-- P5.3b-3 — Cashback (campagnes) + Bonus (CDC_06 §6.1/§6.2). Règles = données, calcul backend seul.
-- Le CRÉDIT du cashback est gaté OFF par défaut (prêt à activer §11) ; le schéma est posé en entier.

-- Campagnes de cashback. taux_bps = points de base (500 = 5 %) → cashback = base*bps/10000 (entier).
-- Plafonds 0 = illimité ; budget_total null = illimité. cree_par = acteur (RBAC en amont, tracé ici).
CREATE TABLE cashback_campagnes (
    id                          UUID         PRIMARY KEY,
    code                        VARCHAR(60)  NOT NULL UNIQUE,
    libelle                     VARCHAR(200) NOT NULL,
    type_operation_source       VARCHAR(24)  NOT NULL,        -- op éligible (ex. PAIEMENT_FACTURE)
    taux_bps                    INT          NOT NULL,
    plafond_par_operation       BIGINT       NOT NULL DEFAULT 0,
    plafond_par_wallet          BIGINT       NOT NULL DEFAULT 0,
    plafond_par_wallet_par_jour BIGINT       NOT NULL DEFAULT 0,
    budget_total                BIGINT,
    date_debut                  TIMESTAMPTZ  NOT NULL,
    date_fin                    TIMESTAMPTZ  NOT NULL,
    actif                       BOOLEAN      NOT NULL DEFAULT TRUE,
    cree_par                    VARCHAR(120) NOT NULL,
    created_at                  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Déterminisme : au plus UNE campagne active par type d'op source (ambiguïté rendue impossible).
-- Conséquence assumée : impossible de préparer la campagne suivante pendant que l'actuelle est active.
CREATE UNIQUE INDEX uq_campagne_active_par_type ON cashback_campagnes (type_operation_source) WHERE actif;

-- Rattachement d'une opération à sa campagne et à son opération source (cashback + clawback).
ALTER TABLE wallet_operations ADD COLUMN campagne_code       VARCHAR(60);
ALTER TABLE wallet_operations ADD COLUMN operation_source_id UUID;
CREATE INDEX idx_wallet_ops_campagne ON wallet_operations (campagne_code);
CREATE INDEX idx_wallet_ops_source   ON wallet_operations (operation_source_id);
