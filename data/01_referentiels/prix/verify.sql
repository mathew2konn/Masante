\set ON_ERROR_STOP off
\pset footer off

\echo '=== 1. Volumétrie ==='
SELECT panier, count(*) AS lignes,
       min(prix_homologue) AS prix_min,
       max(prix_homologue) AS prix_max,
       round(avg(prix_homologue),0) AS prix_moyen
FROM referentiel.prix_homologue GROUP BY panier ORDER BY panier;

SELECT count(*) AS total FROM referentiel.prix_homologue;

\echo '=== 2. Qualité des données ==='
SELECT count(*) FILTER (WHERE libelle_incomplet)          AS libelles_incomplets,
       count(*) FILTER (WHERE dosage_presume = '')        AS dosage_absent,
       count(*) FILTER (WHERE forme_normalisee = '')      AS forme_absente,
       count(*) FILTER (WHERE medicament_id IS NULL)      AS non_rattaches_lnme,
       count(*) FILTER (WHERE prix_homologue < 500)       AS prix_suspects_bas,
       count(*) FILTER (WHERE prix_homologue > 50000)     AS prix_suspects_hauts
FROM referentiel.prix_homologue;

\echo '=== 3. Doublons de libellé (même produit, même source) ==='
SELECT cle_produit, count(*) FROM referentiel.prix_homologue
GROUP BY cle_produit HAVING count(*) > 1;

\echo '=== 4. TEST — le prix homologué est immuable (doit ECHOUER) ==='
UPDATE referentiel.prix_homologue SET prix_homologue = 1
WHERE code_prix = 'PRIX-CMU-HTA-001';

\echo '=== 5. TEST — deux prix chevauchants interdits (doit ECHOUER) ==='
INSERT INTO referentiel.prix_homologue
  (code_prix, libelle_source, prix_homologue, source_id, reference_reglementaire, date_effet)
SELECT 'TEST-DOUBLON', libelle_source, 9999, source_id, 'test', '2025-12-01'
FROM referentiel.prix_homologue WHERE code_prix = 'PRIX-CMU-HTA-001';

\echo '=== 6. TEST — versionnement correct (doit REUSSIR) ==='
UPDATE referentiel.prix_homologue SET date_fin = '2026-01-01'
WHERE code_prix = 'PRIX-CMU-HTA-001';
INSERT INTO referentiel.prix_homologue
  (code_prix, libelle_source, prix_homologue, source_id, reference_reglementaire, date_effet)
SELECT 'PRIX-CMU-HTA-001-V2', libelle_source, 4900, source_id,
       'Décision fictive 2026', '2026-01-01'
FROM referentiel.prix_homologue WHERE code_prix = 'PRIX-CMU-HTA-001';

SELECT code_prix, prix_homologue, date_effet, date_fin,
       (periode @> CURRENT_DATE) AS en_vigueur
FROM referentiel.prix_homologue
WHERE code_prix LIKE 'PRIX-CMU-HTA-001%' ORDER BY date_effet;

\echo '=== 7. TEST — vue d''affichage et écart officine ==='
INSERT INTO referentiel.prix_pharmacie
  (pharmacie_id, prix_homologue_id, prix_releve, origine, statut)
SELECT '11111111-1111-1111-1111-111111111111', id, 5000, 'officine', 'valide'
FROM referentiel.prix_homologue WHERE code_prix = 'PRIX-CMU-HTA-001-V2';

SELECT code_prix, prix_reference, prix_pharmacie, prix_affiche, nature_prix
FROM referentiel.v_prix_affichage WHERE code_prix = 'PRIX-CMU-HTA-001-V2';

SELECT code_prix, prix_homologue, prix_releve, ecart_fcfa, ecart_pct, anomalie
FROM referentiel.v_ecart_prix;

\echo '=== 8. TEST — un prix negatif est refuse (doit ECHOUER) ==='
INSERT INTO referentiel.prix_pharmacie
  (pharmacie_id, prix_homologue_id, prix_releve, origine)
SELECT '11111111-1111-1111-1111-111111111111', id, -100, 'officine'
FROM referentiel.prix_homologue WHERE code_prix = 'PRIX-CMU-HTA-001-V2';
