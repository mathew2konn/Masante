"""P10c-3-i — l'entraînement réel (F15/F16/F17 du plan G1).

``_lignes_fixture`` construit un jeu MINUSCULE mais RÉEL au sens du contrat (aucun générateur
synthétique dans `app/`, Y14) : ce sont des données de test, pas une simulation de production.
"""
from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.config import parametres
from app.entrainement.entrainer import VolumeInsuffisantError, entrainer
from app.entrainement.features import vecteur_features
from app.entrainement.schemas import LigneEntrainement, RequeteEntrainement
from app.main import app

client = TestClient(app)


def _ligne(label: str, **kwargs) -> LigneEntrainement:
    base = {
        "bande_age": "25-44",
        "sexe": "F",
        "symptomes": [12, 47],
        "constantes": {"temperature": 37.5, "pouls": 80},
        "duree_jours": 2,
        "intensite": 4,
        "grossesse": False,
        "score_antecedents": 5,
        "label": label,
    }
    base.update(kwargs)
    return LigneEntrainement.model_validate(base)


def _lignes_fixture(n_par_classe: int = 12) -> list[LigneEntrainement]:
    """Trois classes bien séparées dans l'espace des features, pour que l'entraînement converge
    sans ambiguïté — ce test prouve le PIPELINE, pas la qualité clinique d'un modèle (F15 :
    « réel dans son mécanisme, pas validé statistiquement »).
    """
    lignes: list[LigneEntrainement] = []
    for i in range(n_par_classe):
        lignes.append(_ligne(
            "adaptee", bande_age="15-24",
            constantes={"temperature": 37.0 + (i % 3) * 0.1, "pouls": 75},
            intensite=2, score_antecedents=0,
        ))
        lignes.append(_ligne(
            "sur_triage", bande_age="45-64",
            constantes={"temperature": 37.2, "pouls": 78},
            intensite=3, score_antecedents=2,
        ))
        lignes.append(_ligne(
            "sous_triage", bande_age="65+",
            constantes={"temperature": 39.5 + (i % 3) * 0.1, "pouls": 130},
            intensite=9, score_antecedents=18,
        ))
    return lignes


# ═══ vecteur_features — pur, sans réseau, sans modèle (motif fraud-detection) ═══

def test_vecteur_features_bande_connue_ok():
    v = vecteur_features(_ligne("adaptee", bande_age="15-24"))
    assert v["age_bande_ordinale"] == 3.0
    assert v["sexe_f"] == 1.0


def test_vecteur_features_bande_inconnue_leve():
    with pytest.raises(ValueError, match="Bande d'âge inconnue"):
        vecteur_features(_ligne("adaptee", bande_age="120-130"))


def test_vecteur_features_valeurs_absentes_deviennent_nan():
    """XGBoost gère nativement les valeurs manquantes — aucune imputation inventée ici."""
    import math
    v = vecteur_features(_ligne("adaptee", bande_age=None, sexe=None, constantes={}))
    assert math.isnan(v["age_bande_ordinale"])
    assert math.isnan(v["sexe_f"])
    assert math.isnan(v["temperature"])


def test_vecteur_features_nb_symptomes_est_un_compte():
    v = vecteur_features(_ligne("adaptee", symptomes=[1, 2, 3]))
    assert v["nb_symptomes"] == 3.0


# ═══ Refus sous le seuil minimal — double garde (F15) ═══

def test_entrainer_directement_sous_seuil_leve():
    """La garde Python EN DIRECT — dédoublée de la garde HTTP ci-dessous (motif P6.6b)."""
    requete = RequeteEntrainement(pays_code="CI", numero_export=1, lignes=[_ligne("adaptee")])
    with pytest.raises(VolumeInsuffisantError, match="requises au minimum"):
        entrainer(requete)


def test_entrainement_sous_seuil_refuse_422():
    corps = {
        "pays_code": "CI", "numero_export": 1,
        "lignes": [_ligne("adaptee").model_dump()],
    }
    r = client.post("/api/v1/triage/entrainement", json=corps)
    assert r.status_code == 422
    reponse = r.json()
    assert reponse["motif"] == "volume_insuffisant"
    assert reponse["message"]  # jamais vide (Rule-005)


# ═══ Refus quand une classe n'a qu'un exemplaire (dédoublé, une couche un vecteur) ═══

def _lignes_classe_rare() -> list[LigneEntrainement]:
    """Assez de lignes pour passer le seuil, mais `sous_triage` n'y figure QU'UNE fois."""
    n = parametres.seuil_min_entrainement
    return [_ligne("adaptee") for _ in range(n - 1)] + [_ligne("sous_triage")]


def test_entrainer_classe_a_un_seul_exemplaire_refuse_en_la_nommant():
    """Sans cette garde, `train_test_split(stratify=...)` lève un `ValueError` rendu en 500 nu.

    Le cas est attendu, pas théorique : la classe rare des premiers retours réels sera
    `sous_triage`, la seule dangereuse. Le refus doit la NOMMER (motif P10b-1).
    """
    requete = RequeteEntrainement(
        pays_code="CI", numero_export=1, lignes=_lignes_classe_rare()
    )
    with pytest.raises(VolumeInsuffisantError, match="sous_triage"):
        entrainer(requete)


def test_classe_a_un_seul_exemplaire_refuse_422_et_pas_500():
    corps = {
        "pays_code": "CI", "numero_export": 1,
        "lignes": [ligne.model_dump() for ligne in _lignes_classe_rare()],
    }
    r = client.post("/api/v1/triage/entrainement", json=corps)
    assert r.status_code == 422, "un 500 opaque plutôt qu'un refus motivé serait le défaut"
    reponse = r.json()
    assert reponse["motif"] == "volume_insuffisant"
    assert "sous_triage" in reponse["message"]


# ═══ Entraînement réussi ═══

@pytest.mark.parametrize("n_par_classe", [max(10, (parametres.seuil_min_entrainement // 3) + 1)])
def test_entrainement_reussi_retourne_metriques_et_run_id(n_par_classe):
    lignes = _lignes_fixture(n_par_classe)
    requete = RequeteEntrainement(pays_code="CI", numero_export=1, lignes=lignes)

    resultat = entrainer(requete)

    assert resultat.mlflow_run_id
    assert resultat.nb_lignes_entrainement + resultat.nb_lignes_test == len(lignes)
    # Nommée à part, jamais absente (F16) : c'est la seule métrique qui dit si le modèle rate
    # systématiquement le cas dangereux.
    assert "rappel_sous_triage" in resultat.metriques
    assert 0.0 <= resultat.metriques["rappel_sous_triage"] <= 1.0
    for cle in ("exactitude", "precision", "rappel", "f1"):
        assert cle in resultat.metriques


def test_entrainement_http_reussi():
    lignes = _lignes_fixture(max(10, (parametres.seuil_min_entrainement // 3) + 1))
    corps = {
        "pays_code": "CI", "numero_export": 2,
        "lignes": [ligne.model_dump() for ligne in lignes],
    }
    r = client.post("/api/v1/triage/entrainement", json=corps)
    assert r.status_code == 200
    reponse = r.json()
    assert reponse["mlflow_run_id"]
    assert reponse["metriques"]["rappel_sous_triage"] is not None


def test_score_toujours_503_apres_un_entrainement():
    """LE VECTEUR CENTRAL DE LA FRONTIÈRE i/ii (Y10/F18 du plan) : entraîner un modèle candidat
    ne branche RIEN sur `/score`. `ModeleTriage.disponible` reste `False` en dur — ce test le
    prouve à l'exécution plutôt que par relecture du code."""
    lignes = _lignes_fixture(max(10, (parametres.seuil_min_entrainement // 3) + 1))
    client.post("/api/v1/triage/entrainement", json={
        "pays_code": "CI", "numero_export": 3,
        "lignes": [ligne.model_dump() for ligne in lignes],
    })

    r = client.post("/api/v1/triage/score", json={"reference": "triage:1"})
    assert r.status_code == 503
    assert r.json()["motif"] == "modele_indisponible"
