-- P5.4c — Canal de notification (CDC_06 §5.4 « notifications avant prélèvement ») via OUTBOX (CDC_03 §8 :
-- écrite dans la même transaction que le changement métier, jamais publiée avant le commit). LIVRAISON
-- SIMULÉE (FT5) : un relais lit les lignes EN_ATTENTE et les « livre » via un adaptateur simulé. Voir ADR-018.

CREATE TABLE notifications_outbox (
    id               UUID         PRIMARY KEY,
    type             VARCHAR(32)  NOT NULL,               -- PRELEVEMENT_IMMINENT | PRELEVEMENT_ECHOUE
    agregat_type     VARCHAR(32)  NOT NULL,               -- ex. 'echeance'
    agregat_id       UUID         NOT NULL,
    destinataire_ref VARCHAR(120) NOT NULL,               -- utilisateur (réf posée par la passerelle)
    canal_souhaite   VARCHAR(16)  NOT NULL DEFAULT 'AUTO',
    charge_utile     JSONB        NOT NULL,               -- contenu (montant, date d'échéance, libellé…)
    statut           VARCHAR(16)  NOT NULL,               -- EN_ATTENTE | ENVOYEE | ECHOUEE
    canal_livraison  VARCHAR(16),                         -- canal réellement utilisé (simulé)
    detail           VARCHAR(255),                        -- motif d'échec le cas échéant
    tentatives       INT          NOT NULL DEFAULT 0,
    version          BIGINT       NOT NULL DEFAULT 0,
    cree_le          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    traite_le        TIMESTAMPTZ
);

-- Le relais lit les lignes en attente par ancienneté.
CREATE INDEX idx_notifications_statut_date ON notifications_outbox (statut, cree_le);
CREATE INDEX idx_notifications_destinataire ON notifications_outbox (destinataire_ref);
