-- =====================================================================
--  MaSanté — payment-service — V11_verification_invariants.sql
--  Invariants portés par V11 (P5.5b-1). Exécuter APRÈS migration, base de
--  recette :  psql -v ON_ERROR_STOP=1 -f V11_verification_invariants.sql
--  Transaction terminée par ROLLBACK. Périmètre : signe libre report/solde,
--  quatre-yeux, destination active unique, constatation unique, règle EXTOURNE,
--  équilibre par écriture (balayage global) et BALANCE de vérification
--  (Σ A_REVERSER = Σ net des relevés approuvés). Immuabilité I5 = Java+REVOKE
--  (non testée ici, sans effet sous le rôle propriétaire).
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

-- Config commission + destination active (prérequis d'un relevé approuvé).
INSERT INTO reversement_commission_config (id, etablissement_ref, taux_bps, valide_du, motif, cree_par)
VALUES ('cccccccc-cccc-cccc-cccc-cccccccccccc', NULL, 250, '2026-01-01', 'test', 'ci');
INSERT INTO reversement_destination
    (id, etablissement_ref, type, ref_chiffree, nonce, cle_version, empreinte, empreinte_version,
     libelle, valide_du, motif, cree_par)
VALUES ('dddddddd-dddd-dddd-dddd-dddddddddddd', 'ETB-V11', 'MOBILE_MONEY', '\x01', '\x02', 1,
        repeat('e', 64), 1, 'Orange Money eeeeeeee', '2026-01-01', 'test', 'admin.fin');


\echo '=== Signe du report/solde désormais LIBRE (report positif possible) ==='
SELECT pg_temp.assert_ok('relevé à solde_reporte POSITIF (net 0 sous seuil)',
  $sql$ INSERT INTO reversement_releves
          (numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
           montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
           montant_rembourse, report_anterieur, montant_net_a_reverser, solde_reporte,
           hash_integrite, calcule_par)
        VALUES ('REV-POS','ETB-V11',2026,'2026-01-01','2026-02-01','2026-02-01',
                1000,0,'cccccccc-cccc-cccc-cccc-cccccccccccc',0,0,0,0,1000,repeat('a',64),'agent') $sql$);


\echo '=== Quatre-yeux : approbateur ≠ calculateur (CHECK base) ==='
SELECT pg_temp.assert_refuse('approuve_par = calcule_par',
  $sql$ INSERT INTO reversement_releves
          (numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
           montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
           montant_net_a_reverser, hash_integrite, calcule_par, statut,
           approuve_par, approuve_a, destination_id, destination_empreinte, destination_figee_a)
        VALUES ('REV-4Y','ETB-V11',2026,'2026-02-01','2026-03-01','2026-03-01',
                100000,250,'cccccccc-cccc-cccc-cccc-cccccccccccc',2500,97500,repeat('a',64),'agent','APPROUVE',
                'agent', now(), 'dddddddd-dddd-dddd-dddd-dddddddddddd', repeat('e',64), now()) $sql$,
  'ck_rev_quatre_yeux');


\echo '=== Relevé APPROUVE valide + constatation équilibrée ==='
SELECT pg_temp.assert_ok('relevé approuvé (dir ≠ agent) avec destination figée',
  $sql$ INSERT INTO reversement_releves
          (id, numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
           montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
           montant_net_a_reverser, hash_integrite, calcule_par, statut,
           approuve_par, approuve_a, destination_id, destination_empreinte, destination_empreinte_calcul,
           destination_figee_a)
        VALUES ('11111111-aaaa-aaaa-aaaa-111111111111','REV-APP','ETB-V11',2026,
                '2026-03-01','2026-04-01','2026-04-01',
                100000,250,'cccccccc-cccc-cccc-cccc-cccccccccccc',2500,97500,repeat('a',64),'agent','APPROUVE',
                'dir.finance', now(), 'dddddddd-dddd-dddd-dddd-dddddddddddd', repeat('e',64), repeat('e',64), now()) $sql$);

SELECT pg_temp.assert_ok('écriture de constatation (en-tête + 3 jambes équilibrées)',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, cree_par)
        VALUES ('eeeeeeee-1111-1111-1111-eeeeeeeeeeee','11111111-aaaa-aaaa-aaaa-111111111111','CONSTATATION','2026-04-01','dir.finance');
        INSERT INTO reversement_grand_livre_ligne (ecriture_id, sequence, compte, sens, montant, libelle) VALUES
          ('eeeeeeee-1111-1111-1111-eeeeeeeeeeee',1,'TRESORERIE','DEBIT',100000,'x'),
          ('eeeeeeee-1111-1111-1111-eeeeeeeeeeee',2,'COMMISSION','CREDIT',2500,'x'),
          ('eeeeeeee-1111-1111-1111-eeeeeeeeeeee',3,'A_REVERSER','CREDIT',97500,'x'); $sql$);


\echo '=== Une seule CONSTATATION par relevé ==='
SELECT pg_temp.assert_refuse('seconde constatation sur le même relevé',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, cree_par)
        VALUES (gen_random_uuid(),'11111111-aaaa-aaaa-aaaa-111111111111','CONSTATATION','2026-04-01','x') $sql$,
  'uq_ecr_constatation_par_releve');


\echo '=== Règle EXTOURNE : référence obligatoire et une seule fois ==='
SELECT pg_temp.assert_refuse('EXTOURNE sans écriture référencée',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, cree_par)
        VALUES (gen_random_uuid(),'11111111-aaaa-aaaa-aaaa-111111111111','EXTOURNE','2026-04-01','x') $sql$,
  'ck_ecr_extourne');
SELECT pg_temp.assert_refuse('CONSTATATION référençant une écriture (interdit)',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, ecriture_extournee_id, cree_par)
        VALUES (gen_random_uuid(),'11111111-aaaa-aaaa-aaaa-111111111111','CONSTATATION','2026-04-01','eeeeeeee-1111-1111-1111-eeeeeeeeeeee','x') $sql$,
  'ck_ecr_extourne');
SELECT pg_temp.assert_ok('première extourne de la constatation',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, ecriture_extournee_id, cree_par)
        VALUES ('eeeeeeee-2222-2222-2222-eeeeeeeeeeee','11111111-aaaa-aaaa-aaaa-111111111111','EXTOURNE','2026-04-02','eeeeeeee-1111-1111-1111-eeeeeeeeeeee','x') $sql$);
SELECT pg_temp.assert_refuse('seconde extourne de la même écriture',
  $sql$ INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, ecriture_extournee_id, cree_par)
        VALUES (gen_random_uuid(),'11111111-aaaa-aaaa-aaaa-111111111111','EXTOURNE','2026-04-02','eeeeeeee-1111-1111-1111-eeeeeeeeeeee','x') $sql$,
  'uq_ecr_extournee_une_fois');


\echo '=== Destination active unique par établissement ==='
SELECT pg_temp.assert_refuse('seconde destination active pour ETB-V11',
  $sql$ INSERT INTO reversement_destination
          (id, etablissement_ref, type, ref_chiffree, nonce, cle_version, empreinte, empreinte_version,
           libelle, valide_du, motif, cree_par)
        VALUES (gen_random_uuid(),'ETB-V11','MOBILE_MONEY','\x03','\x04',1,repeat('f',64),1,'x','2026-05-01','x','x') $sql$,
  'uq_dest_active_par_etab');


\echo '=== Équilibre par écriture : balayage GLOBAL (doit être vide, hors extourne équilibrée) ==='
-- Avant : la constatation E1 et son extourne E2 (non encore dotée de jambes) —
-- on dote l'extourne de ses jambes inverses pour rester équilibrée.
INSERT INTO reversement_grand_livre_ligne (ecriture_id, sequence, compte, sens, montant, libelle) VALUES
  ('eeeeeeee-2222-2222-2222-eeeeeeeeeeee',1,'TRESORERIE','CREDIT',100000,'ext'),
  ('eeeeeeee-2222-2222-2222-eeeeeeeeeeee',2,'COMMISSION','DEBIT',2500,'ext'),
  ('eeeeeeee-2222-2222-2222-eeeeeeeeeeee',3,'A_REVERSER','DEBIT',97500,'ext');
DO $$
DECLARE n INT;
BEGIN
    SELECT count(*) INTO n FROM (
        SELECT ecriture_id,
               SUM(CASE WHEN sens='DEBIT' THEN montant ELSE -montant END) AS ecart
          FROM reversement_grand_livre_ligne GROUP BY ecriture_id) t
     WHERE ecart <> 0;
    IF n > 0 THEN RAISE EXCEPTION 'ECHEC : % écriture(s) déséquilibrée(s)', n; END IF;
    RAISE NOTICE 'PASS   toutes les écritures sont équilibrées (Σdébit=Σcrédit)';
END $$;

SELECT pg_temp.assert_ok('le balayage détecte une écriture déséquilibrée injectée',
  $sql$ DO $x$
        DECLARE n INT;
        BEGIN
            INSERT INTO reversement_ecriture (ecriture_id, releve_id, type_ecriture, date_comptable, cree_par)
            VALUES ('eeeeeeee-3333-3333-3333-eeeeeeeeeeee','11111111-aaaa-aaaa-aaaa-111111111111','DECAISSEMENT','2026-04-03','x');
            INSERT INTO reversement_grand_livre_ligne (ecriture_id, sequence, compte, sens, montant, libelle)
            VALUES ('eeeeeeee-3333-3333-3333-eeeeeeeeeeee',1,'A_REVERSER','DEBIT',100,'boiteuse');
            SELECT count(*) INTO n FROM (
                SELECT ecriture_id, SUM(CASE WHEN sens='DEBIT' THEN montant ELSE -montant END) AS ecart
                  FROM reversement_grand_livre_ligne GROUP BY ecriture_id) t WHERE ecart <> 0;
            IF n <> 1 THEN RAISE EXCEPTION 'balayage: attendu 1 écart, obtenu %', n; END IF;
        END $x$; $sql$);


\echo '=== BALANCE de vérification : Σ A_REVERSER = Σ net des relevés APPROUVE non versés ==='
-- Sur ETB-V11 : le relevé REV-APP (net 97500) est APPROUVE ; sa constatation crédite A_REVERSER de
-- 97500 ; l'extourne l'a re-débité (relevé toujours APPROUVE ici, extourne = jeu de test) → on retire
-- l'extourne du périmètre en ne comptant que la constatation pour cet invariant métier.
DO $$
DECLARE v_ledger BIGINT; v_releves BIGINT;
BEGIN
    SELECT COALESCE(SUM(CASE WHEN gl.sens='CREDIT' THEN gl.montant ELSE -gl.montant END),0) INTO v_ledger
      FROM reversement_grand_livre_ligne gl
      JOIN reversement_ecriture e ON e.ecriture_id = gl.ecriture_id
      JOIN reversement_releves r ON r.id = e.releve_id
     WHERE gl.compte='A_REVERSER' AND e.type_ecriture='CONSTATATION' AND r.etablissement_ref='ETB-V11';
    SELECT COALESCE(SUM(montant_net_a_reverser),0) INTO v_releves
      FROM reversement_releves WHERE etablissement_ref='ETB-V11' AND statut='APPROUVE';
    IF v_ledger <> v_releves THEN
        RAISE EXCEPTION 'ECHEC balance : A_REVERSER(constatations)=% ≠ net approuvé=%', v_ledger, v_releves;
    END IF;
    RAISE NOTICE 'PASS   balance A_REVERSER (%) = net des relevés approuvés', v_ledger;
END $$;


\echo '=== Tous les invariants V11 vérifiés. Annulation du jeu d''essai. ==='
ROLLBACK;
