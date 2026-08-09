"""fraud-detection-service (CDC_05) — API FastAPI.

Microservice IA autonome (§1.7) : détection hybride (règles + XGBoost + SHAP) de facturations
suspectes et comportements anormaux sur les signaux paiement internes. DÉTECTION SEULE.
Swagger : /docs — Santé : /health, /ready.
"""
from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from . import schemas
from .config import parametres
from .extraction.principal import SignatairePrincipal
from .extraction.source import (
    AdaptateurSignauxPaiement,
    PieceIntrouvable,
    SourceIndisponible,
)
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


def _construire_source() -> AdaptateurSignauxPaiement | None:
    """Adaptateur d'extraction si le secret est fourni ; sinon None → extraction en 502 honnête."""
    if not parametres.principal_secret:
        return None
    signataire = SignatairePrincipal(
        parametres.principal_secret, parametres.principal_sub, parametres.principal_role)
    return AdaptateurSignauxPaiement(
        parametres.payment_base_url, signataire, parametres.http_timeout_s)


source_signaux = _construire_source()


@app.exception_handler(SourceIndisponible)
def _source_indisponible(_: Request, exc: SourceIndisponible) -> JSONResponse:
    # Dégradation HONNÊTE : la source réelle est absente → on refuse de scorer plutôt que d'inventer.
    return JSONResponse(status_code=502, content={"detail": str(exc)})


@app.exception_handler(PieceIntrouvable)
def _piece_introuvable(_: Request, exc: PieceIntrouvable) -> JSONResponse:
    return JSONResponse(status_code=404, content={"detail": str(exc)})


def _source_active() -> AdaptateurSignauxPaiement:
    if source_signaux is None:
        raise SourceIndisponible(
            "Extraction non configurée (FRAUD_PRINCIPAL_SECRET absent). Utilisez /score ou /scan "
            "avec des signaux fournis, ou configurez l'accès au paiement.")
    return source_signaux


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


@app.post("/api/v1/fraud/score-ref", response_model=schemas.ResultatFraude, tags=["fraude"])
def score_ref(requete: schemas.RequeteScoreRef) -> schemas.ResultatFraude:
    """Extrait les signaux RÉELS d'une facture depuis le paiement (incrément A) puis la score.

    Source injoignable → 502 honnête (jamais de score inventé) ; référence inconnue → 404.
    """
    signal = _source_active().signaux(requete.reference, requete.as_of)
    return service.scorer(signal)


@app.post("/api/v1/fraud/scan-refs", response_model=schemas.ReponseScan, tags=["fraude"])
def scan_refs(requete: schemas.RequeteScanRefs) -> schemas.ReponseScan:
    """Extrait un LOT de factures au même cut-off depuis le paiement puis les score (§6.9)."""
    signaux = _source_active().signaux_lot(requete.references, requete.as_of)
    return service.scorer_lot(signaux)
