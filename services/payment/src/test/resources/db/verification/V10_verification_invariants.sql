-- =====================================================================
--  MaSanté — payment-service — V10_verification_invariants.sql (RÉDUIT)
--  Vérifie les invariants RÉELLEMENT portés par V10__reversements.sql.
--  À exécuter APRÈS la migration, sur une base de recette (preuve G2 live) :
--      psql -v ON_ERROR_STOP=1 -f V10_verification_invariants.sql
--  Transaction terminée par ROLLBACK : ne laisse aucune donnée.
--
--  PÉRIMÈTRE V10 (hybride ciblé — ADR-016) :
--    §0 soldee_a immuable (SEUL trigger) ; I1 uq_ligne_* ; I2 période active +
--    annulation ; I7 ≤1 successeur vivant ; I3 vue en-tête/lignes ;
--    ck_rev_equation / ck_rev_cutoff / ck_ligne_coherence_type ; I6 taux unique.
--  HORS PÉRIMÈTRE (→ V11/P5.5b) : quatre-yeux, destination figée, grand livre.
--  NON testé ici : immuabilité I5 des relevés/lignes = garantie Java (absence de
--    mutateur) + REVOKE déclaratif, sans effet sous le rôle propriétaire (dev).
-- =====================================================================

\set ON_ERROR_STOP on
BEGIN;

CREATE OR REPLACE FUNCTION pg_temp.assert_ok(p_nom TEXT, p_sql TEXT)
RETURNS void AS $$
BEGIN
    EXECUTE p_sql;
    RAISE NOTICE 'PASS   %', p_nom;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION pg_temp.assert_refuse(p_nom TEXT, p_sql TEXT, p_fragment TEXT)
RETURNS void AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
    EXCEPTION WHEN others THEN
        IF position(lower(p_fragment) IN lower(SQLERRM)) = 0 THEN
            RAISE EXCEPTION 'ECHEC [%] : refus attendu sur « % », obtenu « % »', p_nom, p_fragment, SQLERRM;
        END IF;
        RAISE NOTICE 'PASS   % (refusé comme attendu)', p_nom;
        RETURN;
    END;
    RAISE EXCEPTION 'ECHEC [%] : aucune erreur levée, l''invariant ne protège rien', p_nom;
END;
$$ LANGUAGE plpgsql;

-- Taux de commission ouvert (aucun seed en V10 : on en crée un pour le test).
INSERT INTO reversement_commission_config (etablissement_ref, taux_bps, valide_du, motif, cree_par)
VALUES (NULL, 250, '2026-01-01T00:00:00Z', 'test recette', 'ci');

CREATE OR REPLACE FUNCTION pg_temp.creer_releve(
    p_numero TEXT, p_debut TEXT, p_fin TEXT,
    p_brut BIGINT DEFAULT 100000, p_com BIGINT DEFAULT 2500,
    p_remb BIGINT DEFAULT 0, p_report BIGINT DEFAULT 0, p_precedent UUID DEFAULT NULL)
RETURNS UUID AS $$
DECLARE
    v_theorique BIGINT := p_brut - p_com - p_remb + p_report;
    v_id UUID;
BEGIN
    INSERT INTO reversement_releves
        (numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
         montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
         montant_rembourse, report_anterieur, montant_net_a_reverser, solde_reporte,
         releve_precedent_id, hash_integrite, calcule_par)
    VALUES (p_numero, 'ETB-TEST', 2026, p_debut::timestamptz, p_fin::timestamptz, p_fin::timestamptz,
            p_brut, 250, (SELECT id FROM reversement_commission_config WHERE valide_au IS NULL LIMIT 1),
            p_com, p_remb, p_report, GREATEST(v_theorique, 0), LEAST(v_theorique, 0),
            p_precedent, repeat('a', 64), 'agent.calcul')
    RETURNING id INTO v_id;
    RETURN v_id;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION pg_temp.creer_ligne_facture(p_releve UUID, p_facture UUID,
    p_regle BIGINT DEFAULT 100000, p_com BIGINT DEFAULT 2500)
RETURNS void AS $$
BEGIN
    INSERT INTO reversement_releve_lignes
        (releve_id, type_ligne, facture_id, piece_reference, piece_datee_a,
         montant_regle_impute, montant_commission_ligne, montant_net_ligne)
    VALUES (p_releve, 'FACTURE', p_facture, 'FCT-TEST', '2026-01-15T10:00:00Z',
            p_regle, p_com, p_regle - p_com);
END;
$$ LANGUAGE plpgsql;


\echo '=== §0 — soldee_a posée au passage à PAYEE, puis immuable ==='
INSERT INTO factures (id, numero, etablissement_ref, exercice, devise, sous_total_ht, montant_ttc,
                      reste_a_payer, montant_regle, statut, hash_integrite)
VALUES ('11111111-1111-1111-1111-111111111111','F-TEST-1','ETB-TEST',2026,'XOF',100000,100000,100000,0,'EMISE','h');

SELECT pg_temp.assert_ok('passage à PAYEE',
  $sql$ UPDATE factures SET statut='PAYEE', montant_regle=100000
         WHERE id='11111111-1111-1111-1111-111111111111' $sql$);
DO $$ BEGIN
    IF (SELECT soldee_a FROM factures WHERE id='11111111-1111-1111-1111-111111111111') IS NULL THEN
        RAISE EXCEPTION 'ECHEC : soldee_a non renseignée au passage à PAYEE';
    END IF;
    RAISE NOTICE 'PASS   soldee_a renseignée automatiquement';
END $$;
SELECT pg_temp.assert_refuse('réécriture de soldee_a',
  $sql$ UPDATE factures SET soldee_a = TIMESTAMPTZ '2020-01-01Z' WHERE id='11111111-1111-1111-1111-111111111111' $sql$,
  'immuable');
SELECT pg_temp.assert_ok('autre update de la facture reste possible',
  $sql$ UPDATE factures SET numero='F-TEST-1-BIS' WHERE id='11111111-1111-1111-1111-111111111111' $sql$);


\echo '=== I1 — une facture imputée sur au plus un relevé actif ==='
SELECT pg_temp.creer_releve('REV-T-0001','2026-01-01','2026-02-01') AS r1 \gset
SELECT pg_temp.assert_ok('relevé de janvier + ligne facture',
  format($sql$ SELECT pg_temp.creer_ligne_facture(%L,'11111111-1111-1111-1111-111111111111') $sql$, :'r1'));
SELECT pg_temp.assert_refuse('même facture sur un second relevé actif',
  $sql$ SELECT pg_temp.creer_ligne_facture(pg_temp.creer_releve('REV-T-0002','2026-02-01','2026-03-01'),
                                           '11111111-1111-1111-1111-111111111111') $sql$,
  'uq_ligne_facture_imputee_une_fois');


\echo '=== I2 — période verrouillée, libérée par annulation ==='
SELECT pg_temp.assert_refuse('second relevé sur période déjà couverte',
  $sql$ SELECT pg_temp.creer_releve('REV-T-0099','2026-01-01','2026-02-01') $sql$,
  'uq_releve_periode_active');
SELECT pg_temp.assert_ok('annulation du relevé de janvier',
  $sql$ UPDATE reversement_releves SET statut='ANNULE', annule_par='comptable', annule_a=now(),
               motif_annulation='taux erroné' WHERE numero='REV-T-0001';
        UPDATE reversement_releve_lignes SET releve_actif=false
         WHERE releve_id=(SELECT id FROM reversement_releves WHERE numero='REV-T-0001') $sql$);
SELECT pg_temp.assert_ok('recalcul de janvier avec la même facture (tentative 2)',
  $sql$ SELECT pg_temp.creer_ligne_facture(pg_temp.creer_releve('REV-T-0001B','2026-01-01','2026-02-01'),
                                           '11111111-1111-1111-1111-111111111111') $sql$);


\echo '=== I7 — au plus un successeur vivant par relevé ==='
-- Relevés à assiette vide (brut 0) : gardent la vue I3 vide, sans lignes.
SELECT pg_temp.creer_releve('REV-CHAIN-A','2026-03-01','2026-04-01',0,0) AS a \gset
SELECT pg_temp.assert_ok('successeur B pointant sur A',
  format($sql$ SELECT pg_temp.creer_releve('REV-CHAIN-B','2026-04-01','2026-05-01',0,0,0,0,%L) $sql$, :'a'));
SELECT pg_temp.assert_refuse('deuxième successeur vivant pointant sur A',
  format($sql$ SELECT pg_temp.creer_releve('REV-CHAIN-C','2026-05-01','2026-06-01',0,0,0,0,%L) $sql$, :'a'),
  'uq_releve_successeur_vivant');


\echo '=== Équation, cut-off, cohérence de ligne ==='
SELECT pg_temp.assert_refuse('net incohérent avec brut − commission',
  $sql$ INSERT INTO reversement_releves
          (numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
           montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
           montant_net_a_reverser, hash_integrite, calcule_par)
        VALUES ('REV-BAD','ETB-TEST',2026,'2026-06-01','2026-07-01','2026-07-01',100000,250,
                (SELECT id FROM reversement_commission_config WHERE valide_au IS NULL LIMIT 1),
                2500,99999,repeat('a',64),'agent') $sql$,
  'ck_rev_equation');
SELECT pg_temp.assert_refuse('cut-off antérieur à la fin de période',
  $sql$ INSERT INTO reversement_releves
          (numero, etablissement_ref, exercice, periode_debut, periode_fin, cut_off_t,
           montant_brut_du, taux_commission_bps, commission_config_id, montant_commission,
           montant_net_a_reverser, hash_integrite, calcule_par)
        VALUES ('REV-BAD2','ETB-TEST',2026,'2026-06-01','2026-07-01','2026-06-15',0,250,
                (SELECT id FROM reversement_commission_config WHERE valide_au IS NULL LIMIT 1),
                0,0,repeat('a',64),'agent') $sql$,
  'ck_rev_cutoff');
SELECT pg_temp.assert_refuse('ligne FACTURE portant un remboursement',
  format($sql$ INSERT INTO reversement_releve_lignes
          (releve_id, type_ligne, facture_id, piece_reference, piece_datee_a,
           montant_regle_impute, montant_rembourse_impute, montant_net_ligne)
        VALUES (%L,'FACTURE',gen_random_uuid(),'F-X',now(),0,5000,-5000) $sql$, :'a'),
  'ck_ligne_coherence_type');


\echo '=== I6 — un seul taux de commission ouvert par périmètre ==='
SELECT pg_temp.assert_refuse('deuxième taux plateforme ouvert',
  $sql$ INSERT INTO reversement_commission_config (etablissement_ref, taux_bps, valide_du, motif, cree_par)
        VALUES (NULL, 300, '2026-01-01', 'doublon', 'ci') $sql$,
  'uq_cfg_un_seul_taux_ouvert');


\echo '=== I3 — réconciliation en-tête / lignes (doit être vide) ==='
DO $$
DECLARE n INT;
BEGIN
    SELECT count(*) INTO n FROM v_reversement_anomalies;
    IF n > 0 THEN RAISE EXCEPTION 'ECHEC : % relevé(s) en écart en-tête/lignes', n; END IF;
    RAISE NOTICE 'PASS   aucun écart en-tête / lignes';
END $$;


\echo '=== Tous les invariants V10 vérifiés. Annulation du jeu d''essai. ==='
ROLLBACK;
