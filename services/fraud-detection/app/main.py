"""fraud-detection-service (CDC_05) — API FastAPI.

Microservice IA autonome (§1.7) : détection hybride (règles + XGBoost + SHAP) de facturations
suspectes et comportements anormaux sur les signaux paiement internes. DÉTECTION SEULE.
Swagger : /docs — Santé : /health, /ready.
"""
from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import FastAPI

from . import schemas
from .config import parametres
from .ml.modele import modele
from .service import ServiceFraude

DESCRIPTION = (
    "Détection de fraude (CDC_05, fraud-detection-service). Approche hybride imposée : "
    "moteur de règles déterministe + modèle XGBoost + explicabilité SHAP. **Détection seule** : "
    "aucune action automatique, revue humaine requise (CDC_05 §9.1). Modèle entraîné sur données "
    "**synthétiques de démonstration**, non validé cliniquement. Dégradation gracieuse : si le "
    "modèle est indisponible, réponse en règles seules."
)


@asynccontextmanager
async def lifespan(app: FastAPI):
    modele.charger(parametres.modele_path)
    yield


app = FastAPI(
    title="MASANTÉ — fraud-detection-service",
    version="1.0.0",
    description=DESCRIPTION,
    lifespan=lifespan,
)

service = ServiceFraude(modele, parametres)


@app.get("/health", tags=["sante"])
def health() -> dict[str, str]:
    return {"status": "UP"}


@app.get("/ready", tags=["sante"])
def ready() -> dict[str, object]:
    return {
        "status": "READY",
        "modele_charge": modele.disponible,
        "mode": "hybride" if modele.disponible else "regles_seules",
    }


@app.post("/api/v1/fraud/score", response_model=schemas.ResultatFraude, tags=["fraude"])
def score(signal: schemas.SignalFacturation) -> schemas.ResultatFraude:
    """Score un signalement unitaire et renvoie un résultat explicable."""
    return service.scorer(signal)


@app.post("/api/v1/fraud/scan", response_model=schemas.ReponseScan, tags=["fraude"])
def scan(requete: schemas.RequeteScan) -> schemas.ReponseScan:
    """Score un LOT de signalements (§6.9 comportements anormaux) et renvoie un résumé."""
    return service.scorer_lot(requete.signaux)
