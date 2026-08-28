"""Socle P10c-2-i : le contrat d'API et la dégradation honnête (F5/F6).

Aucun test de score RÉEL n'a de sens ici : il n'y a pas de modèle (Y5). Ce qui est prouvé, c'est le
contrat (validation Pydantic) et le refus honnête — jamais un score inventé.
"""
from __future__ import annotations

from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)

EXEMPLE = {
    "reference": "triage:1234",
    "age": 34,
    "sexe": "F",
    "symptomes": [12, 47],
    "constantes": {"temperature": 38.9, "pouls": 96},
    "duree_jours": 2,
    "intensite": 5,
    "grossesse": False,
    "niveau_protocole": "modere",
}


def test_health():
    r = client.get("/health")
    assert r.status_code == 200
    assert r.json() == {"status": "UP"}


def test_ready_sans_modele():
    r = client.get("/ready")
    assert r.status_code == 200
    corps = r.json()
    assert corps["modele_charge"] is False
    # Y5 : pas de repli "regles_seules", ce service n'en a aucun sans modèle.
    assert corps["mode"] == "sans_modele"
    assert "regles_seules" not in corps.values()


def test_score_refuse_honnetement_faute_de_modele():
    r = client.post("/api/v1/triage/score", json=EXEMPLE)
    assert r.status_code == 503
    corps = r.json()
    assert corps["motif"] == "modele_indisponible"
    assert corps["message"]  # jamais vide (précédent Rule-005)
    assert "1234" in corps["message"] or "triage:1234" in corps["message"]


def test_score_minimal_valide_aussi_refuse_503():
    """Tous les champs sauf `reference` sont facultatifs : le contrat les accepte, la dégradation
    reste 503 — la validation ne dépend pas de la richesse de la requête."""
    r = client.post("/api/v1/triage/score", json={"reference": "triage:1"})
    assert r.status_code == 503


def test_reference_absente_est_rejetee_422():
    """Preuve F5 : la validation Pydantic fonctionne, indépendamment du modèle."""
    corps = dict(EXEMPLE)
    del corps["reference"]
    r = client.post("/api/v1/triage/score", json=corps)
    assert r.status_code == 422


def test_sexe_hors_vocabulaire_est_rejete_422():
    corps = dict(EXEMPLE, sexe="X")
    r = client.post("/api/v1/triage/score", json=corps)
    assert r.status_code == 422


def test_intensite_hors_bornes_est_rejetee_422():
    corps = dict(EXEMPLE, intensite=11)
    r = client.post("/api/v1/triage/score", json=corps)
    assert r.status_code == 422


def test_docs_disponibles():
    assert client.get("/docs").status_code == 200
