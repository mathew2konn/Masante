"""triage-service (CDC_05 §5) — API FastAPI.

Socle du P10c-2-i (CDC_05 §5.5.4) : le contrat d'API, la validation Pydantic, la dégradation
honnête. AUCUN modèle aujourd'hui (F5) — ``/api/v1/triage/score`` répond 503 avec un motif
explicite, jamais un score inventé. Aucune règle de triage ici : elles vivent dans les protocoles
Laravel, sous quatre validations cliniques (Y5).
Swagger : /docs — Santé : /health, /ready.
"""
from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from . import schemas
from .modele import modele
from .service import ModeleIndisponibleError, ServiceTriageIa

DESCRIPTION = (
    "Assistance IA au triage (CDC_05, triage-service). Complète les protocoles, ne les remplace "
    "jamais (§1.3) — le triage n'est jamais un diagnostic (§1.4). Socle P10c-2-i : AUCUN modèle "
    "chargé aujourd'hui, dégradation honnête (503) à chaque appel. Le modèle réel, entraîné sur "
    "des issues cliniques réelles (jamais synthétiques), arrive en P10c-3."
)


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Rien à charger tant qu'aucun modèle n'existe (F5) — le lifespan reste ici pour que le point
    # d'accroche du chargement futur soit déjà le bon endroit, pas un endroit à inventer.
    yield


app = FastAPI(
    title="MASANTÉ — triage-service",
    version="0.1.0",
    description=DESCRIPTION,
    lifespan=lifespan,
)

service = ServiceTriageIa(modele)


@app.exception_handler(ModeleIndisponibleError)
def _modele_indisponible(_: Request, exc: ModeleIndisponibleError) -> JSONResponse:
    # Dégradation HONNÊTE (F6) : le service refuse plutôt que d'inventer. C'est Laravel qui absorbe
    # et rend le triage complet avec la mention — jamais ce service qui prétend décider.
    return JSONResponse(
        status_code=503,
        content=schemas.ReponseIndisponible(motif="modele_indisponible", message=str(exc)).model_dump(),
    )


@app.get("/health", tags=["sante"])
def health() -> dict[str, str]:
    return {"status": "UP"}


@app.get("/ready", tags=["sante"])
def ready() -> dict[str, object]:
    return {
        "status": "READY",
        "modele_charge": modele.disponible,
        # Pas de "regles_seules" (Y5) : ce service n'a aucun repli à proposer sans modèle.
        "mode": "sans_modele",
    }


@app.post(
    "/api/v1/triage/score",
    responses={503: {"model": schemas.ReponseIndisponible}},
    tags=["triage"],
)
def score(requete: schemas.RequeteTriageScore) -> schemas.ReponseIndisponible:
    """Score une demande de triage. Aujourd'hui : 503 systématique, honnête (F6)."""
    return service.scorer(requete)
