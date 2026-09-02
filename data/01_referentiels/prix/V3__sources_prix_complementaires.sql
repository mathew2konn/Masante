-- =============================================================================
--  V3 — Enregistrement des autres sources de prix
--
--  Aucune donnée de prix n'est insérée ici : seules les sources sont déclarées,
--  avec leur niveau de confiance. Une source « indicatif » n'est jamais
--  affichée comme prix officiel (voir referentiel.v_prix_affichage).
-- =============================================================================

BEGIN;

INSERT INTO referentiel.source_prix
    (code, libelle, type_source, autorite, url, opposable, niveau_confiance)
VALUES
    -- Source de référence à obtenir : c'est elle qui fera foi à terme.
    ('AIRP_HOMOLOGATION',
     'AIRP — prix homologués des médicaments (arrêté n° 249/MSHP/MCIPPME du 04/04/2019 et textes suivants)',
     'officiel', 'Autorité Ivoirienne de Régulation Pharmaceutique',
     'https://airp.ci/datapharma/liste-des-medicaments-enregistres', true, 5),

    -- Secteur public : prix de cession, non applicables en officine privée.
    ('NPSP_CESSION',
     'Nouvelle PSP-CI — prix de cession aux établissements publics',
     'secteur_public', 'Nouvelle Pharmacie de la Santé Publique',
     'http://www.npsp.ci/listeProduits.php', false, 4),

    -- Agrégateur privé : utile pour la couverture, jamais opposable.
    ('PHARMAPRIX',
     'PharmaPrix — indicateur de prix des produits pharmaceutiques (source privée, date de mise à jour non déclarée)',
     'indicatif', NULL, 'http://pharmaprix.ngh.ci/', false, 1),

    ('PHARMACIES_DE_GARDE_CI',
     'pharmacies-de-garde.ci — liste de prix indicative (source privée)',
     'indicatif', NULL,
     'https://www.pharmacies-de-garde.ci/prix-des-medicaments-en-pharmacie-en-cote-divoire/',
     false, 1)
ON CONFLICT (code) DO NOTHING;

COMMIT;

-- -----------------------------------------------------------------------------
-- Règle d'arbitrage entre sources, à appliquer côté service d'intégration :
--
--   1. AIRP_HOMOLOGATION      → fait foi, écrase tout
--   2. CNAM_CMU               → fait foi sur le périmètre remboursable
--   3. NPSP_CESSION           → secteur public uniquement
--   4. PHARMAPRIX / autres    → comblent les trous, affichés « prix indicatif »
--
-- Un prix issu d'une source non opposable ne doit jamais être présenté sans
-- la mention de son caractère indicatif et de sa date de relevé.
-- -----------------------------------------------------------------------------
