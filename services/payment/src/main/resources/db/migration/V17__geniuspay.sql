-- P5.6 / lot 7 — Intégration GeniusPay (montage A : un compte marchand par établissement).
-- ADR-044 (B3 : pas de second port ni de seconde machine — B6 : pas de seconde table d'événements).
--
-- CE QUI N'EST PAS FAIT ICI, ET POURQUOI :
--   * Pas de table `transaction_paiement` autonome (§5 du prompt v2). `payments` porte déjà montant,
--     devise, canal, telephone_masque, etablissement_ref, statut partagé et version. Les recopier
--     ici produirait deux vérités sur les mêmes faits — le défaut que ce projet refuse partout
--     ailleurs. La table ci-dessous ne porte QUE ce que `payments` ne sait pas dire.
--     C'est le motif exact de `carte_transactions` (P5.4a), repris sans être réinventé.
--   * Pas de nouvelle table d'événements webhook (B6) : `carte_evenements_webhook` porte déjà le
--     discriminant `psp` et la déduplication `UNIQUE(psp, evenement_id)`. Elle est ÉTENDUE.

-- --------------------------------------------------------------------------------------------
-- 1. Identifiants marchands (§6.2) — la garde des secrets de tiers.
-- --------------------------------------------------------------------------------------------
-- Montage A : le service détient la clé `sk_` et le secret webhook `whsec_` de chaque partenaire.
-- Les deux subissent le MÊME traitement (chiffrement enveloppe AES-256-GCM), sans distinction :
-- un secret webhook compromis permet de forger un « paiement réussi », il n'est pas moins sensible
-- que la clé d'API.
--
-- `slug` — le point de conception du montage A. À la réception d'un webhook, la signature doit être
-- vérifiée AVANT toute lecture de confiance du corps ; or le secret dépend de l'établissement, que
-- seul le corps nommerait. L'URL de rappel porte donc un discriminant, enregistré par compte
-- marchand chez GeniusPay. Il est OPAQUE et ALÉATOIRE, jamais `etablissement_ref` : une URL
-- énumérable révélerait la liste des partenaires. Le slug SÉLECTIONNE le secret candidat ; c'est le
-- HMAC qui DÉCIDE (un slug valide avec une signature fausse est rejeté comme n'importe quoi).
CREATE TABLE identifiants_marchand (
    id                     UUID         PRIMARY KEY,
    etablissement_ref      VARCHAR(120) NOT NULL,
    psp                    VARCHAR(32)  NOT NULL,                    -- 'geniuspay'
    slug                   VARCHAR(64)  NOT NULL,                    -- opaque, aléatoire, jamais l'etablissement_ref
    cle_publique           VARCHAR(160) NOT NULL,                    -- pk_… : publique par nature, en clair
    cle_secrete_chiffree   BYTEA        NOT NULL,                    -- sk_… : AES-256-GCM
    cle_secrete_nonce      BYTEA        NOT NULL,
    secret_webhook_chiffre BYTEA,                                    -- whsec_… : NULL tant que le webhook n'est pas créé
    secret_webhook_nonce   BYTEA,
    cle_version            SMALLINT     NOT NULL DEFAULT 1,          -- rotation du matériel de chiffrement
    environnement          VARCHAR(16)  NOT NULL DEFAULT 'sandbox',
    actif                  BOOLEAN      NOT NULL DEFAULT TRUE,
    date_rotation          TIMESTAMPTZ,
    cree_le                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le                 TIMESTAMPTZ  NOT NULL DEFAULT now(),
    version                BIGINT       NOT NULL DEFAULT 0,
    -- Le slug est la clé de résolution du secret webhook : il doit être unique globalement, pas
    -- seulement par PSP — deux PSP partageant un slug rendraient la sélection ambiguë.
    CONSTRAINT uq_marchand_slug          UNIQUE (slug),
    -- Un seul jeu d'identifiants par établissement et par PSP. La rotation remplace en place
    -- (date_rotation), elle n'empile pas une seconde ligne : deux lignes actives feraient réapparaître
    -- l'essai de secrets en cascade que le slug existe précisément pour éviter.
    CONSTRAINT uq_marchand_etab_psp      UNIQUE (etablissement_ref, psp),
    CONSTRAINT ck_marchand_nonce_secret  CHECK (
        (secret_webhook_chiffre IS NULL AND secret_webhook_nonce IS NULL)
     OR (secret_webhook_chiffre IS NOT NULL AND secret_webhook_nonce IS NOT NULL)),
    -- D7 : sandbox uniquement. Le garde-fou applicatif refuse déjà le démarrage hors sandbox ; celui-ci
    -- interdit qu'une ligne « live » entre en base par un chemin qui contournerait le service.
    CONSTRAINT ck_marchand_environnement CHECK (environnement = 'sandbox')
);

CREATE INDEX idx_marchand_etab ON identifiants_marchand (etablissement_ref, actif);

-- --------------------------------------------------------------------------------------------
-- 2. Transactions GeniusPay — table SATELLITE de `payments`, sur le modèle de `carte_transactions`.
-- --------------------------------------------------------------------------------------------
CREATE TABLE geniuspay_transactions (
    id                   UUID         PRIMARY KEY,
    paiement_id          UUID         NOT NULL REFERENCES payments (id),
    -- `MS-{etablissement}-{ULID}` — envoyée en `metadata.order_id` à CHAQUE appel, sans exception.
    -- C'est elle qui permet de rattacher un webhook à une transaction dont on n'a pas la référence
    -- passerelle (cas INITIEE_INCERTAINE, §7.4.b). Unique à vie, jamais réutilisée.
    reference_interne    VARCHAR(128) NOT NULL,
    -- Référence GeniusPay. NULL tant qu'elle est inconnue : c'est exactement l'état d'incertitude
    -- que §7.4 interdit de lever par un rejeu.
    reference_passerelle VARCHAR(128),
    -- Facture pour laquelle CE checkout a été ouvert. Ce n'est pas la même chose que
    -- `payments.facture_id`, qui est renseignée APRÈS confirmation et dit « la facture que ce paiement
    -- a soldée ». Ici on dit « la facture visée à l'initiation », connue avant tout résultat. Deux
    -- faits distincts à deux moments distincts — et c'est cette colonne que l'index partiel ci-dessous
    -- doit voir pour être applicable par le moteur.
    facture_id           UUID,
    statut_geniuspay     VARCHAR(32)  NOT NULL,                     -- sous-état backend-only (ADR-044)
    canal                VARCHAR(40),                               -- data.payment_method / payment_provider
    frais_passerelle     BIGINT,                                    -- `fees` — RENVOYÉ par GeniusPay, jamais recalculé
    montant_net          BIGINT,                                    -- `net_amount` — idem
    checkout_url         TEXT,
    -- `expires_at` TEL QUE RENVOYÉ par GeniusPay, jamais un « maintenant + N heures » calculé chez
    -- nous. La vérification V3 a montré 30 minutes là où la documentation annonce 24 h : une durée
    -- recopiée de la doc aurait fait tenir pour ouvert un lien déjà mort.
    expire_le            TIMESTAMPTZ,
    code_erreur          VARCHAR(64),
    initiee_le           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    finalisee_le         TIMESTAMPTZ,
    -- Horodatage de la dernière tentative de levée d'incertitude (§7.4.b) : borne le balayage.
    derniere_verification_le TIMESTAMPTZ,
    version              BIGINT       NOT NULL DEFAULT 0,           -- verrou optimiste
    cree_le              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    maj_le               TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_gp_tx_reference_interne UNIQUE (reference_interne),
    CONSTRAINT uq_gp_tx_reference_psp     UNIQUE (reference_passerelle),
    -- Relation 1:1 avec le paiement partagé, comme pour la carte : un paiement GeniusPay ⇄ au plus
    -- un checkout. Interdit structurellement le double cycle sur un même paiement.
    CONSTRAINT uq_gp_tx_paiement          UNIQUE (paiement_id)
);

-- « Une facture ne peut jamais avoir deux checkouts GeniusPay réussis » (§5).
--
-- L'index est PARTIEL et porté par CETTE table, pas par `payments` : un index global sur
-- `payments (facture_id) WHERE statut = 'SUCCESS'` aurait interdit un second règlement PARTIEL, or
-- `FactureStatut.PARTIELLEMENT_PAYEE` existe et le cumul est un cas légitime (P5.2a). La garantie
-- vaut donc là où elle est vraie : le checkout GeniusPay solde une facture entière (D6), jamais une
-- ligne, donc deux checkouts réussis pour la même facture sont un double débit.
CREATE UNIQUE INDEX uq_gp_tx_facture_reussie
    ON geniuspay_transactions (facture_id)
    WHERE statut_geniuspay = 'REUSSIE' AND facture_id IS NOT NULL;

CREATE INDEX idx_gp_tx_statut ON geniuspay_transactions (statut_geniuspay, initiee_le);

-- --------------------------------------------------------------------------------------------
-- 3. Extension de la table d'événements webhook existante (B6) — aucune duplication.
-- --------------------------------------------------------------------------------------------
-- DETTE DE NOMMAGE, DITE PLUTÔT QUE DÉGUISÉE : la table s'appelle `carte_evenements_webhook` parce
-- qu'elle est née avec le module carte (P5.4a). Elle porte désormais aussi les événements GeniusPay,
-- et son nom ne le dit pas. La renommer imposerait de toucher `ServiceCarte` et son test — un module
-- VALIDÉ G5 — pour un gain purement cosmétique, hors du périmètre déclaré de ce lot. Le fait qui
-- compte est porté par la colonne `psp`, qui existe depuis l'origine ; le nom est un vestige.
--
-- Les colonnes ajoutées sont TOUTES nullables : les événements carte déjà en base n'ont jamais eu ces
-- valeurs, et leur en inventer une serait un mensonge d'archive.
ALTER TABLE carte_evenements_webhook
    -- SHA-256 du corps brut. Second filet d'idempotence si le payload ne portait pas de champ `id`
    -- (deux clés, pas une : §5 du prompt v2).
    ADD COLUMN empreinte_corps      CHAR(64),
    -- Horodatage DÉCLARÉ par le PSP (en-tête `X-Webhook-Timestamp`), à ne pas confondre avec
    -- `recu_le` qui est le nôtre. C'est l'écart entre les deux qui fonde le rejet anti-rejeu.
    ADD COLUMN horodatage_declare   BIGINT,
    ADD COLUMN environnement        VARCHAR(16),
    ADD COLUMN signature_valide     BOOLEAN,
    ADD COLUMN motif_rejet          VARCHAR(120),
    -- `X-Webhook-Retry` : numéro de tentative déclaré. Optionnel côté GeniusPay — on ne s'en sert
    -- jamais pour l'idempotence (la documentation le donne pour facultatif), seulement pour le
    -- diagnostic.
    ADD COLUMN numero_tentative     INT,
    ADD COLUMN reference_passerelle VARCHAR(128),
    ADD COLUMN adresse_ip           VARCHAR(64),
    -- Corps INTÉGRAL, tel que reçu. C'est la seule forme qui permette de rejouer une vérification de
    -- signature lors d'un litige : un corps normalisé ou masqué ne prouverait plus rien.
    -- La contrepartie est assumée à la SOURCE et non ici : l'initiation n'envoie ni nom ni téléphone
    -- du patient à GeniusPay, donc aucune donnée personnelle ne peut revenir dans ce champ.
    ADD COLUMN corps_brut           TEXT;

CREATE INDEX idx_webhook_statut_recu ON carte_evenements_webhook (statut_traitement, recu_le);
CREATE INDEX idx_webhook_ref_psp     ON carte_evenements_webhook (reference_passerelle);
