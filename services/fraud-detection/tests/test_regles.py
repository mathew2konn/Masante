"""Règles déterministes — logique pure (G3)."""
from app.config import Parametres
from app.domain.regles import evaluer_regles
from app.schemas import SignalFacturation

P = Parametres()


def _signal(**kw) -> SignalFacturation:
    base = dict(
        reference="R", etablissement_ref="E",
        montant_ttc=30000, montant_couvert=21000, reste_a_payer=9000,
        montant_acte=30000, montant_acte_reference=28000,
        nb_factures_etablissement_30j=10, nb_actes_identiques_jour=3,
        nb_remboursements_carte_7j=0, montant_cumule_wallet_24h=40000,
        nb_ops_wallet_1h=2, heure_operation=10, delai_facture_paiement_minutes=20,
    )
    base.update(kw)
    return SignalFacturation(**base)


def test_signal_sain_aucune_regle():
    res = evaluer_regles(_signal(), P)
    assert res.regles == []
    assert res.score == 0
    assert res.force_escalade is False


def test_couverture_superieure_ttc_force_escalade():
    # couvert > ttc : incohérence dure ; reste=0 pour ne pas déséquilibrer
    res = evaluer_regles(_signal(montant_ttc=10000, montant_couvert=15000, reste_a_payer=0), P)
    codes = {r.code for r in res.regles}
    assert "INCOHERENCE_PRISE_EN_CHARGE" in codes
    # couvert+reste (15000) != ttc (10000) => déséquilibre aussi
    assert "DESEQUILIBRE_COMPTABLE" in codes
    assert res.force_escalade is True


def test_desequilibre_comptable():
    res = evaluer_regles(_signal(montant_ttc=30000, montant_couvert=21000, reste_a_payer=5000), P)
    assert any(r.code == "DESEQUILIBRE_COMPTABLE" for r in res.regles)
    assert res.force_escalade is True


def test_montant_acte_aberrant():
    res = evaluer_regles(_signal(montant_acte=200000, montant_acte_reference=28000), P)
    assert any(r.code == "MONTANT_ACTE_ABERRANT" for r in res.regles)


def test_velocite_et_actes_et_remboursements():
    res = evaluer_regles(
        _signal(nb_factures_etablissement_30j=80, nb_actes_identiques_jour=40, nb_remboursements_carte_7j=9),
        P,
    )
    codes = {r.code for r in res.regles}
    assert {"VELOCITE_FACTURATION", "ACTES_REPETES", "REMBOURSEMENTS_RAFALE"} <= codes


def test_cumul_et_velocite_wallet_et_horaire():
    res = evaluer_regles(
        _signal(montant_cumule_wallet_24h=5_000_000, nb_ops_wallet_1h=30, heure_operation=3),
        P,
    )
    codes = {r.code for r in res.regles}
    assert {"CUMUL_WALLET_ANORMAL", "VELOCITE_WALLET", "HORAIRE_INHABITUEL"} <= codes


def test_seuils_sont_des_donnees():
    # abaisser le seuil via config doit déclencher la règle sur un signal auparavant sain
    p = Parametres(seuil_factures_30j=5)
    res = evaluer_regles(_signal(nb_factures_etablissement_30j=6), p)
    assert any(r.code == "VELOCITE_FACTURATION" for r in res.regles)
