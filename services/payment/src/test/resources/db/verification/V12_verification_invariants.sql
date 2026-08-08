-- =====================================================================
--  MaSanté — payment-service — V12_verification_invariants.sql
--  Invariants portés par V12 (P5.5b-2 : décaissement). Exécuter APRÈS
--  migration, base de recette :
--    psql -v ON_ERROR_STOP=1 -f V12_verification_invariants.sql
--  Transaction terminée par ROLLBACK. Périmètre : registre de décaissement
--  (statut/montant/devise), anti-double-versement (≤1 EXECUTE, ≤1 EN_COURS,
--  idempotency_key unique), une seule écriture DÉCAISSEMENT par relevé,
--  équilibre de l'écriture de décaissement (2 et 3 jambes), et BALANCE
--  (Σ décaissé = Σ net des relevés EXECUTE).
-- =====================================================================

\set ON_ERROR_STOP on
BEGIN;

CREATE OR REPLACE FUNCTION pg_temp.assert_ok(p_nom TEXT, p_sql TEXT)
RETURNS void AS $$ BEGIN EXECUTE p_sql; RAISE NOTICE 'PASS   %', p_nom; END; $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION pg_temp.assert_refuse(p_nom TEXT, p_sql TEXT, p_fragment TEXT)
RETURNS void AS $$
BEGIN
    BEGIN EXECUTE p_sql;
    EXCEPTION WHEN others THEN
        IF position(lower(p_fragment) IN lower(SQLERRM)) = 0 THEN
            RAISE EXCEPTION 'ECHEC [%] : refus attendu « % », obtenu « % »', p_nom, p_fragment, SQLERRM;
        END IF;
        RAISE NOTICE 'PASS   % (refusé comme attendu)', p_nom; RETURN;
    END;
    RAISE EXCEPTION 'ECHEC [%] : aucune erreur levée', p_nom;
END; $$ LANGUAGE plpgsql;

-- Prérequis : taux, destination active, un relevé APPROUVE (net 97500) prêt à verser.
INSERT INTO reversement_commission_config (id, etablissement_ref, taux_bps, valide_du, motif, cree_par)
VALUES ('cccccccc-cccc-cccc-cccc-cccccccccccc', NULL, 250, '2026-01-01', 'test', 'ci');
INSERT INTO reversement_destination
    (id, etablissement_ref, type, ref_chiffree, nonce, cle_version, empreinte, empreinte_version,
     libelle, valide_du, motif, cree_par)
VALUES ('dddddddd-dddd-dddd-dddd-dddddddddddd', 'ETB-V12', 'MOBILE_MONEY', '\x01', '\x02', 1,
        repeat('e', 64), 1, 'Orange Money eeeeeeee', '2026-01-01', 'test', 'admin.fin');
INSERT INTO reversement_releves
    (id, numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
     montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
     montant_net_a_reverser, hash_integrite, calcule_par, statut,
     approuve_par, approuve_a, destination_id, destination_empreinte, destination_empreinte_calcul, destination_figee_a)
VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','REV-V12','ETB-V12',2026,
        '2026-03-01','2026-04-01','2026-04-01',
        100000,250,'cccccccc-cccc-cccc-cccc-cccccccccccc',2500,97500,repeat('a',64),'agent','APPROUVE',
        'dir.finance', now(), 'dddddddd-dddd-dddd-dddd-dddddddddddd', repeat('e',64), repeat('e',64), now());


\echo '=== Registre de décaissement : garde-fous de colonne ==='
SELECT pg_temp.assert_refuse('statut hors énumération',
  $sql$ INSERT INTO reversement_decaissement (releve_id, destination_id, statut, montant_net, idempotency_key, cree_par)
        VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','dddddddd-dddd-dddd-dddd-dddddddddddd','PENDING',97500,'k0','x') $sql$,
  'ck_dec_statut');
SELECT pg_temp.assert_refuse('montant net ≤ 0',
  $sql$ INSERT INTO reversement_decaissement (releve_id, destination_id, statut, montant_net, idempotency_key, cree_par)
        VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','dddddddd-dddd-dddd-dddd-dddddddddddd','EN_COURS',0,'k0','x') $sql$,
  'ck_dec_montant');
SELECT pg_temp.assert_refuse('devise ≠ XOF',
  $sql$ INSERT INTO reversement_decaissement (releve_id, destination_id, statut, montant_net, devise, idempotency_key, cree_par)
        VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','dddddddd-dddd-dddd-dddd-dddddddddddd','EN_COURS',97500,'EUR','k0','x') $sql$,
  'ck_dec_devise');


\echo '=== Anti-double-versement : ≤1 EN_COURS puis ≤1 EXECUTE par relevé ==='
SELECT pg_temp.assert_ok('1re tentative EN_COURS',
  $sql$ INSERT INTO reversement_decaissement (id, releve_id, destination_id, statut, montant_net, idempotency_key, cree_par)
        VALUES ('d1111111-1111-1111-1111-111111111111','11111111-aaaa-aaaa-aaaa-111111111111','dddddddd-dddd-dddd-dddd-dddddddddddd','EN_COURS',97500,'idem-1','dir.tresor') $sql$);
SELECT pg_temp.assert_refuse('2e EN_COURS concurrent interdit',
  $sql$ INSERT INTO reversement_decaissement (releve_id, destination_id, statut, montant_net, idempotency_key, cree_par)
        VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','dddddddd-dddd-dddd-dddd-dddddddddddd','EN_COURS',97500,'idem-2','x') $sql$,
  'uq_decaissement_en_cours_par_releve');
SELECT pg_temp.assert_refuse('même idempotency_key réutilisée',
  $sql$ INSERT INTO reversement_decaissement (releve_id, destination_id, statut, montant_net, idempotency_key, cree_par)
        VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','dddddddd-dddd-dddd-dddd-dddddddddddd','ECHOUE',97500,'idem-1','x') $sql$,
  'uq_decaissement_idempotency');

-- La tentative passe EXECUTE (réf passerelle + frais rapportés).
SELECT pg_temp.assert_ok('tentative EN_COURS → EXECUTE',
  $sql$ UPDATE reversement_decaissement SET statut='EXECUTE', reference_passerelle='SIMRV-EXEC-AAA', frais=0
         WHERE id='d1111111-1111-1111-1111-111111111111' $sql$);
SELECT pg_temp.assert_refuse('2e EXECUTE sur le même relevé interdit',
  $sql$ INSERT INTO reversement_decaissement (releve_id, destination_id, statut, montant_net, idempotency_key, cree_par)
        VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','dddddddd-dddd-dddd-dddd-dddddddddddd','EXECUTE',97500,'idem-3','x') $sql$,
  'uq_decaissement_reussi_par_releve');


\echo '=== Écriture de DÉCAISSEMENT : une seule par relevé, équilibrée ==='
SELECT pg_temp.assert_ok('écriture DÉCAISSEMENT sans frais (2 jambes équilibrées)',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, cree_par)
        VALUES ('eeeeeeee-4444-4444-4444-eeeeeeeeeeee','11111111-aaaa-aaaa-aaaa-111111111111','DECAISSEMENT','2026-04-05','dir.tresor');
        INSERT INTO reversement_grand_livre_ligne (ecriture_id, sequence, compte, sens, montant, libelle) VALUES
          ('eeeeeeee-4444-4444-4444-eeeeeeeeeeee',1,'A_REVERSER','DEBIT',97500,'x'),
          ('eeeeeeee-4444-4444-4444-eeeeeeeeeeee',2,'TRESORERIE','CREDIT',97500,'x'); $sql$);
SELECT pg_temp.assert_refuse('seconde écriture DÉCAISSEMENT sur le même relevé',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, cree_par)
        VALUES (gen_random_uuid(),'11111111-aaaa-aaaa-aaaa-111111111111','DECAISSEMENT','2026-04-05','x') $sql$,
  'uq_ecr_decaissement_par_releve');

-- Variante avec frais : la trésorerie sort net+frais, la plateforme porte les frais (3 jambes).
SELECT pg_temp.assert_ok('écriture DÉCAISSEMENT avec frais (3 jambes : FRAIS_PASSERELLE au débit)',
  $sql$ INSERT INTO reversement_releves
          (id, numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
           montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
           montant_net_a_reverser, hash_integrite, calcule_par, statut,
           approuve_par, approuve_a, destination_id, destination_empreinte, destination_figee_a)
        VALUES ('22222222-aaaa-aaaa-aaaa-222222222222','REV-V12B','ETB-V12',2026,
                '2026-04-01','2026-05-01','2026-05-01',
                100000,250,'cccccccc-cccc-cccc-cccc-cccccccccccc',2500,97500,repeat('a',64),'agent','EXECUTE',
                'dir.finance', now(), 'dddddddd-dddd-dddd-dddd-dddddddddddd', repeat('e',64), now());
        INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, cree_par)
        VALUES ('eeeeeeee-5555-5555-5555-eeeeeeeeeeee','22222222-aaaa-aaaa-aaaa-222222222222','DECAISSEMENT','2026-05-05','dir.tresor');
        INSERT INTO reversement_grand_livre_ligne (ecriture_id, sequence, compte, sens, montant, libelle) VALUES
          ('eeeeeeee-5555-5555-5555-eeeeeeeeeeee',1,'A_REVERSER','DEBIT',97500,'x'),
          ('eeeeeeee-5555-5555-5555-eeeeeeeeeeee',2,'FRAIS_PASSERELLE','DEBIT',500,'x'),
          ('eeeeeeee-5555-5555-5555-eeeeeeeeeeee',3,'TRESORERIE','CREDIT',98000,'x'); $sql$);


\echo '=== Équilibre par écriture : balayage GLOBAL (doit être vide) ==='
DO $$
DECLARE n INT;
BEGIN
    SELECT count(*) INTO n FROM (
        SELECT ecriture_id, SUM(CASE WHEN sens='DEBIT' THEN montant ELSE -montant END) AS ecart
          FROM reversement_grand_livre_ligne GROUP BY ecriture_id) t
     WHERE ecart <> 0;
    IF n > 0 THEN RAISE EXCEPTION 'ECHEC : % écriture(s) déséquilibrée(s)', n; END IF;
    RAISE NOTICE 'PASS   toutes les écritures (constatation + décaissement) sont équilibrées';
END $$;


\echo '=== BALANCE : Σ décaissé (registre EXECUTE) = Σ net des relevés EXECUTE ==='
-- Pour cet invariant, on marque le 1er relevé EXECUTE (versement réussi) : le registre EXECUTE et les
-- relevés EXECUTE doivent concorder sur le net.
UPDATE reversement_releves SET statut='EXECUTE' WHERE id='11111111-aaaa-aaaa-aaaa-111111111111';
-- Aligner le registre du 2e relevé (créé EXECUTE plus haut, sans ligne registre) pour un test cohérent.
INSERT INTO reversement_decaissement (releve_id, destination_id, statut, montant_net, frais, reference_passerelle, idempotency_key, cree_par)
VALUES ('22222222-aaaa-aaaa-aaaa-222222222222','dddddddd-dddd-dddd-dddd-dddddddddddd','EXECUTE',97500,500,'SIMRV-EXEC-BBB','idem-b','dir.tresor');
DO $$
DECLARE v_registre BIGINT; v_releves BIGINT;
BEGIN
    SELECT COALESCE(SUM(montant_net),0) INTO v_registre
      FROM reversement_decaissement d
      JOIN reversement_releves r ON r.id = d.releve_id
     WHERE d.statut='EXECUTE' AND r.etablissement_ref='ETB-V12';
    SELECT COALESCE(SUM(montant_net_a_reverser),0) INTO v_releves
      FROM reversement_releves WHERE etablissement_ref='ETB-V12' AND statut='EXECUTE';
    IF v_registre <> v_releves THEN
        RAISE EXCEPTION 'ECHEC balance : décaissé(registre)=% ≠ net EXECUTE(relevés)=%', v_registre, v_releves;
    END IF;
    RAISE NOTICE 'PASS   balance : Σ décaissé (%) = Σ net des relevés EXECUTE', v_registre;
END $$;


\echo '=== Tous les invariants V12 vérifiés. Annulation du jeu d''essai. ==='
ROLLBACK;
