"""Fusion hybride + Rule-005 — logique pure (G3)."""
from app.config import Parametres
from app.domain.fusion import fusionner
from app.domain.regles import evaluer_regles
from app.schemas import SignalFacturation

P = Parametres()


def _signal(**kw) -> SignalFacturation:
    base = dict(reference="R", etablissement_ref="E", montant_ttc=30000,
                montant_couvert=21000, reste_a_payer=9000, montant_acte=30000,
                montant_acte_reference=28000, nb_factures_etablissement_30j=10,
                nb_actes_identiques_jour=3, heure_operation=10)
    base.update(kw)
    return SignalFacturation(**base)


def test_regles_seules_mode_et_confiance_reduite():
    s = _signal()
    res = fusionner(s, evaluer_regles(s, P), None, [], P, "regles_seules")
    assert res.mode == "regles_seules"
    assert res.probabilite_ml is None
    assert res.confiance == "reduite"
    assert "indisponible" in res.limites.lower()


def test_explication_jamais_vide_meme_signal_sain():
    s = _signal()
    res = fusionner(s, evaluer_regles(s, P), None, [], P, "regles_seules")
    assert res.explication
    assert any("aucun signal" in e.lower() for e in res.explication)


def test_limites_toujours_presentes_rule005():
    s = _signal()
    res = fusionner(s, evaluer_regles(s, P), 0.1, [], P, "hybride")
    assert res.limites
    assert "détection seule" in res.limites.lower()
    assert res.action.startswith("AUCUNE")


def test_incoherence_dure_escalade_malgre_ml_bas():
    # couvert>ttc → force_escalade ; même avec proba ML basse, niveau doit être TRES_SUSPECT
    s = _signal(montant_ttc=10000, montant_couvert=15000, reste_a_payer=0)
    res = fusionner(s, evaluer_regles(s, P), 0.01, [], P, "hybride")
    assert res.niveau == "TRES_SUSPECT"
    assert res.score >= P.seuil_tres_suspect


def test_score_borne_0_100():
    s = _signal(nb_factures_etablissement_30j=999, nb_actes_identiques_jour=999,
                nb_remboursements_carte_7j=999, montant_cumule_wallet_24h=10**9,
                nb_ops_wallet_1h=999, heure_operation=2, montant_acte=10**8,
                montant_acte_reference=1000)
    res = fusionner(s, evaluer_regles(s, P), 0.99, [], P, "hybride")
    assert 0 <= res.score <= 100


def test_niveau_normal_quand_rien():
    s = _signal()
    res = fusionner(s, evaluer_regles(s, P), 0.05, [], P, "hybride")
    assert res.niveau == "NORMAL"
