"""Contrat d'API en mode DÉGRADÉ (règles seules).

Ces tests tournent pendant le build Docker AVANT l'entraînement : aucun artefact de modèle n'existe,
donc le lifespan laisse ``modele.disponible = False``. Ils prouvent la dégradation gracieuse (§1.7) et
que le schéma accepte les incohérences (couvert > ttc) que la fraude produit.
"""
from fastapi.testclient import TestClient

from app.main import app


def test_health():
    with TestClient(app) as client:
        assert client.get("/health").json()["status"] == "UP"


def test_ready_mode_degrade_sans_modele():
    with TestClient(app) as client:
        body = client.get("/ready").json()
        assert body["modele_charge"] is False
        assert body["mode"] == "regles_seules"


def test_score_incoherence_acceptee_et_signalee():
    signal = {
        "reference": "FACT-TEST-1", "etablissement_ref": "ETB-1",
        "montant_ttc": 10000, "montant_couvert": 15000, "reste_a_payer": 0,
        "montant_acte": 10000, "montant_acte_reference": 9000,
    }
    with TestClient(app) as client:
        r = client.post("/api/v1/fraud/score", json=signal)
        assert r.status_code == 200
        body = r.json()
        assert body["mode"] == "regles_seules"
        assert body["niveau"] == "TRES_SUSPECT"
        assert body["explication"]  # jamais vide
        assert body["action"].startswith("AUCUNE")


def test_scan_lot_resume():
    req = {"signaux": [
        {"reference": "A", "etablissement_ref": "E", "montant_ttc": 30000,
         "montant_couvert": 20000, "reste_a_payer": 10000, "montant_acte": 30000,
         "montant_acte_reference": 28000},
        {"reference": "B", "etablissement_ref": "E", "montant_ttc": 10000,
         "montant_couvert": 15000, "reste_a_payer": 0, "montant_acte": 10000,
         "montant_acte_reference": 9000},
    ]}
    with TestClient(app) as client:
        body = client.post("/api/v1/fraud/scan", json=req).json()
        assert body["resume"]["total"] == 2
        assert body["resume"]["tres_suspect"] >= 1
        assert body["resume"]["mode"] == "regles_seules"
