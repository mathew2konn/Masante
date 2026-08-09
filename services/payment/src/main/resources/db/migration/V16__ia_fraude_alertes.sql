-- B1 (approfondissement fraude, incrément B) — Routage d'alerte IA vers le contrôleur plateforme.
-- Le paiement ORCHESTRE (extrait les signaux → interroge le fraud-detection-service → persiste les
-- alertes ≥ SUSPECT → émet une notification Outbox vers ADMIN_FINANCE). DÉTECTION SEULE : aucune action
-- automatique (pas de gel), la suite relève d'une revue humaine (CDC_05 §9.1, ADR-017). Voir ADR-020.
--
-- Table DISTINCTE de `fraud_alertes` (P5.3b-2) : celle-ci est l'alerte IA analytique sur une FACTURE
-- (niveau NORMAL/SUSPECT/TRES_SUSPECT + facteurs SHAP), l'autre est la garde temps-réel WALLET (palier
-- GEL/CHALLENGE, montant tenté). Sémantiques différentes → séparation honnête (cf. ADR-014).

CREATE TABLE ia_fraude_alertes (
    id                UUID         PRIMARY KEY,
    facture_ref       VARCHAR(60)  NOT NULL,               -- numéro de facture évaluée
    etablissement_ref VARCHAR(120) NOT NULL,
    patient_ref       VARCHAR(120),
    date_rapport      DATE         NOT NULL,               -- journée du run (cut-off) → idempotence
    niveau            VARCHAR(16)  NOT NULL,               -- SUSPECT | TRES_SUSPECT (NORMAL n'est jamais persisté)
    score             INT          NOT NULL,
    mode              VARCHAR(16)  NOT NULL,               -- hybride | regles_seules (dégradation fraude)
    regles            JSONB        NOT NULL DEFAULT '[]',  -- snapshot des règles déclenchées
    facteurs          JSONB        NOT NULL DEFAULT '[]',  -- snapshot des facteurs SHAP
    signaux           JSONB        NOT NULL DEFAULT '{}',  -- snapshot des signaux extraits (rejouabilité)
    statut            VARCHAR(16)  NOT NULL,               -- OUVERTE | REVUE
    notifiee          BOOLEAN      NOT NULL DEFAULT FALSE, -- une notif Outbox a-t-elle été émise (à la création)
    cut_off           TIMESTAMPTZ  NOT NULL,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    revue_at          TIMESTAMPTZ,
    revue_par         VARCHAR(120),
    version           BIGINT       NOT NULL DEFAULT 0,
    -- Une alerte au plus par facture et par journée de run : rejouer un scan MET À JOUR, ne duplique pas.
    CONSTRAINT uq_ia_fraude_alerte_facture_jour UNIQUE (facture_ref, date_rapport),
    CONSTRAINT ck_ia_fraude_niveau CHECK (niveau IN ('SUSPECT', 'TRES_SUSPECT')),
    CONSTRAINT ck_ia_fraude_statut CHECK (statut IN ('OUVERTE', 'REVUE'))
);

CREATE INDEX idx_ia_fraude_alertes_statut ON ia_fraude_alertes (statut, created_at);
CREATE INDEX idx_ia_fraude_alertes_etab   ON ia_fraude_alertes (etablissement_ref);
