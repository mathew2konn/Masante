"""Feature engineering — cohérence du vecteur (G3)."""
from app.domain.features import NOMS_FEATURES, vecteur_features
from app.schemas import SignalFacturation


def _signal(**kw) -> SignalFacturation:
    base = dict(reference="R", etablissement_ref="E", montant_ttc=30000,
                montant_couvert=21000, reste_a_payer=9000, montant_acte=30000,
                montant_acte_reference=28000)
    base.update(kw)
    return SignalFacturation(**base)


def test_toutes_les_features_presentes_et_ordonnees():
    v = vecteur_features(_signal())
    assert list(v.keys()) == NOMS_FEATURES


def test_ratio_couverture():
    v = vecteur_features(_signal(montant_ttc=30000, montant_couvert=15000, reste_a_payer=15000))
    assert abs(v["ratio_couverture"] - 0.5) < 1e-9


def test_desequilibre_et_couverture_excessive():
    v = vecteur_features(_signal(montant_ttc=10000, montant_couvert=15000, reste_a_payer=0))
    assert v["desequilibre_comptable"] == 5000.0
    assert v["couverture_excessive"] == 1.0


def test_ttc_zero_ne_divise_pas():
    v = vecteur_features(_signal(montant_ttc=0, montant_couvert=0, reste_a_payer=0))
    assert v["ratio_couverture"] == 0.0


def test_ratio_acte_reference_defaut_neutre():
    v = vecteur_features(_signal(montant_acte=5000, montant_acte_reference=0))
    assert v["ratio_acte_reference"] == 1.0
