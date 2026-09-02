"""La prédiction réelle (P10c-3-ii, F22/F23/F25/F27/F36).

Ces vecteurs entraînent un VRAI modèle puis l'interrogent : c'est le seul moyen de prouver que le
vecteur d'entraînement et celui d'inférence coïncident réellement, et que l'explication est produite
par SHAP plutôt que promise par un commentaire.
"""
from __future__ import annotations

import math

import pytest
from fastapi.testclient import TestClient

from app.entrainement.entrainer import entrainer
from app.entrainement.features import NOMS_FEATURES, vecteur_features
from app.entrainement.schemas import LigneEntrainement, RequeteEntrainement
from app.main import app
from app.modele import ModeleAbsentError, modele
from app.schemas import RequeteTriageScore
from app.service import LIMITES, ServiceTriageIa

client = TestClient(app)


def _traits(**kwargs) -> dict:
    base = {
        "bande_age": "25-44",
        "sexe": "F",
        "symptomes": [12, 47],
        "constantes": {"temperature": 38.9, "pouls": 96, "saturation_o2": 97},
        "duree_jours": 2,
        "intensite": 5,
        "grossesse": False,
        "score_antecedents": 6,
    }
    base.update(kwargs)

    return base


@pytest.fixture(scope="module")
def run_id() -> str:
    """Entraîne un vrai modèle une seule fois pour tout ce fichier."""
    lignes = []
    for i in range(36):
        label = ["adaptee", "sur_triage", "sous_triage"][i % 3]
        lignes.append(LigneEntrainement(**_traits(
            intensite=1 + (i % 10),
            score_antecedents=i % 20,
            constantes={"temperature": 36.5 + (i % 5) * 0.7, "pouls": 70 + i},
        ), label=label))

    return entrainer(RequeteEntrainement(pays_code="CI", numero_export=1, lignes=lignes)).mlflow_run_id


# ═══ F25 — LE VECTEUR EST LE MÊME DES DEUX CÔTÉS, PROUVÉ SUR LA MÊME LIGNE ═══

def test_le_vecteur_est_identique_a_l_entrainement_et_a_l_inference():
    """La garantie centrale de F25, vérifiée et non supposée.

    Les deux schémas héritent de `TraitsCliniques` : sur des traits identiques, les deux chemins
    doivent produire exactement le même vecteur. C'est ce qui referme Z8 structurellement.
    """
    traits = _traits()
    depuis_entrainement = vecteur_features(LigneEntrainement(**traits, label="adaptee"))
    depuis_inference = vecteur_features(RequeteTriageScore(**traits, reference="triage:1"))

    # `==` ne suffit PAS : une absence est encodée en `NaN`, et `NaN != NaN`. Deux vecteurs
    # identiques auraient donc l'air différents, et — plus grave dans l'autre sens — un test écrit
    # sans y penser passerait pour avoir comparé ce qu'il n'a pas comparé.
    assert list(depuis_entrainement) == list(depuis_inference)
    for nom in depuis_entrainement:
        gauche, droite = depuis_entrainement[nom], depuis_inference[nom]
        memes_absences = math.isnan(gauche) and math.isnan(droite)
        assert memes_absences or gauche == droite, f"le vecteur diverge sur « {nom} »"


def test_niveau_protocole_et_reference_ne_peuvent_pas_devenir_des_features():
    """D3 de P10c-2-i, tenu par la STRUCTURE et non par un commentaire (F25)."""
    vecteur = vecteur_features(RequeteTriageScore(
        **_traits(), reference="triage:1", niveau_protocole="urgence"))

    assert set(vecteur) == set(NOMS_FEATURES)
    assert "niveau_protocole" not in vecteur
    assert "reference" not in vecteur


def test_les_trois_faits_captes_sont_des_cibles_jamais_des_features():
    """F36 — les mettre en entrée ferait prédire le diagnostic à partir du diagnostic."""
    vecteur = vecteur_features(LigneEntrainement(
        **_traits(), label="adaptee",
        niveau_reel="urgence", maladie_code="MAL000001", specialite_code="cardiologie"))

    assert set(vecteur) == set(NOMS_FEATURES)
    for cible in ("niveau_reel", "maladie_code", "specialite_code", "label"):
        assert cible not in vecteur


# ═══ F23 — LE REGISTRE DÉCIDE, LE SERVICE OBÉIT ═══

def test_le_service_sert_le_run_demande_et_le_dit(run_id):
    service = ServiceTriageIa(modele)
    reponse = service.scorer(RequeteTriageScore(
        **_traits(), reference="triage:1", modele_attendu=run_id))

    # Le run RÉELLEMENT utilisé, jamais celui espéré (§9.2).
    assert reponse.modele_version == run_id


def test_un_run_absent_leve_plutot_que_de_servir_un_autre_artefact(run_id):
    """Le repli silencieux serait la faute : la base dirait X, la réponse viendrait de Y."""
    service = ServiceTriageIa(modele)
    with pytest.raises(ModeleAbsentError, match="absent_evidemment"):
        service.scorer(RequeteTriageScore(
            **_traits(), reference="triage:1", modele_attendu="absent_evidemment"))


# ═══ F27 — RULE-005 : JAMAIS D'EXPLICATION VIDE ═══

def test_la_reponse_porte_toujours_explication_confiance_et_limites(run_id):
    """§11 : « toute réponse doit contenir une explication non vide et cohérente »."""
    service = ServiceTriageIa(modele)
    reponse = service.scorer(RequeteTriageScore(
        **_traits(), reference="triage:1", modele_attendu=run_id))

    assert reponse.classe_predite in ("adaptee", "sur_triage", "sous_triage")
    # Les TROIS classes, toujours — un modèle qui n'en rendrait qu'une cacherait son incertitude.
    assert set(reponse.probabilites) == {"adaptee", "sur_triage", "sous_triage"}
    assert abs(sum(reponse.probabilites.values()) - 1.0) < 0.01

    assert reponse.facteurs, "une explication vide est une violation de Rule-005, pas une dégradation"
    assert len(reponse.facteurs) == len(NOMS_FEATURES)
    assert all(f["feature"] in NOMS_FEATURES for f in reponse.facteurs)
    # Triés par poids décroissant : un relecteur du §9 lit les premiers.
    poids = [float(f["poids"]) for f in reponse.facteurs]
    assert poids == sorted(poids, reverse=True)

    assert reponse.confiance in ("elevee", "moderee", "faible")
    assert reponse.limites == LIMITES
    assert reponse.limites.strip()


def test_les_limites_disent_les_trois_choses_qui_trompent():
    """§9.7 — la phrase n'est pas décorative, son contenu est vérifié."""
    assert "diagnostic" in LIMITES
    assert "urgence" in LIMITES
    assert "observation" in LIMITES


def test_les_seuils_de_confiance_sont_des_donnees():
    """F27 — jamais codés en dur : on les déplace sans redéployer une ligne."""
    assert ServiceTriageIa._confiance(0.99) == "elevee"
    assert ServiceTriageIa._confiance(0.60) == "moderee"
    assert ServiceTriageIa._confiance(0.34) == "faible"


# ═══ LE CHEMIN HTTP COMPLET ═══

def test_score_http_rend_200_avec_une_explication_reelle(run_id):
    r = client.post("/api/v1/triage/score", json=dict(
        _traits(), reference="triage:1234", modele_attendu=run_id, niveau_protocole="modere"))

    assert r.status_code == 200
    corps = r.json()
    assert corps["modele_version"] == run_id
    assert corps["facteurs"]
    assert corps["limites"]
    assert corps["confiance"] in ("elevee", "moderee", "faible")
    # Aucune maladie, aucune spécialité, aucun niveau d'urgence : ce service n'en dit rien (F22).
    assert "maladie" not in corps
    assert "niveau" not in corps
    assert "specialite" not in corps
