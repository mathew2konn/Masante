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
from .entrainement.entrainer import VolumeInsuffisantError, entrainer
from .entrainement.features import BandeAgeInconnueError
from .entrainement.schemas import ReponseEntrainement, ReponseVolumeInsuffisant, RequeteEntrainement
from .modele import ModeleAbsentError, modele
from .service import ModeleIndisponibleError, ServiceTriageIa

DESCRIPTION = (
    "Assistance IA au triage (CDC_05, triage-service). Complète les protocoles, ne les remplace "
    "jamais (§1.3) — le triage n'est jamais un diagnostic (§1.4). `POST /triage/score` (P10c-3-ii) : "
    "prédit si un soignant jugera l'orientation du protocole adaptée, trop haute ou trop basse — "
    "avec explication SHAP, confiance et limites (Rule-005). La réponse n'influence AUCUNE "
    "décision : mode observation, CDC_08 §3 place le raisonnement IA au dernier rang. Le modèle "
    "servi est celui que désigne le registre Laravel, jamais un autre (F23). "
    "`POST /triage/entrainement` (P10c-3-i) : entraînement réel sur des retours médecins réels, "
    "jamais synthétiques (F3/Y14) — candidat de gouvernance."
)


@asynccontextmanager
async def lifespan(app: FastAPI):
    # RIEN n'est chargé au démarrage, et c'est délibéré (F23) : ce service ne décide pas quel modèle
    # répond. Charger « le plus récent » ici lui ferait choisir, et le premier appel pourrait servir
    # un artefact que le registre n'a pas activé. Le chargement est donc déclenché par la requête,
    # qui porte le run décidé par la gouvernance.
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


@app.exception_handler(ModeleAbsentError)
def _modele_absent(_: Request, exc: ModeleAbsentError) -> JSONResponse:
    # F23 — MOTIF DISTINCT, ET C'EST TOUT L'INTÉRÊT.
    #
    # Le registre désigne un modèle que cette instance n'a pas. Ce n'est pas le régime nominal :
    # c'est une panne d'exploitation réelle (artefacts sur le disque du service et non dans MinIO,
    # §10 — limite annoncée). La ranger sous `modele_indisponible` la rendrait indiscernable de
    # « aucun modèle activé », c'est-à-dire invisible. Le statut reste 503 : côté Laravel c'est un
    # refus honnête, qui n'ouvre pas le disjoncteur (F31).
    return JSONResponse(
        status_code=503,
        content=schemas.ReponseIndisponible(
            motif="modele_absent_du_service", message=str(exc)
        ).model_dump(),
    )


@app.exception_handler(VolumeInsuffisantError)
def _volume_insuffisant(_: Request, exc: VolumeInsuffisantError) -> JSONResponse:
    # F15, garde en double de celle de Laravel : 422, jamais un modèle entraîné sur trop peu.
    return JSONResponse(
        status_code=422,
        content=ReponseVolumeInsuffisant(motif="volume_insuffisant", message=str(exc)).model_dump(),
    )


@app.exception_handler(BandeAgeInconnueError)
def _bande_inconnue(_: Request, exc: BandeAgeInconnueError) -> JSONResponse:
    # 422 et non 500 : le fait est précis (les bornes ont divergé entre la config Laravel et la
    # table d'ordre de ce service, F26) et le message NOMME la tranche reçue et celles admises.
    # Vaut pour les deux endpoints — une divergence de bornes casse l'inférence comme
    # l'entraînement.
    return JSONResponse(status_code=422, content={"motif": "bande_age_inconnue", "message": str(exc)})


@app.get("/health", tags=["sante"])
def health() -> dict[str, str]:
    return {"status": "UP"}


@app.get("/ready", tags=["sante"])
def ready() -> dict[str, object]:
    return {
        "status": "READY",
        "modele_charge": modele.disponible,
        # Les runs effectivement en mémoire — pour l'exploitation, jamais pour choisir : un modèle
        # chargé ici n'est pas un modèle « actif », le registre Laravel seul le dit (F23).
        "runs_charges": modele.runs_charges(),
        # Pas de "regles_seules" (Y5) : ce service n'a aucun repli à proposer sans modèle.
        "mode": "observation",
    }


@app.post(
    "/api/v1/triage/score",
    responses={503: {"model": schemas.ReponseIndisponible}},
    tags=["triage"],
)
def score(requete: schemas.RequeteTriageScore) -> schemas.ReponseTriageScore:
    """Prédit le jugement qu'un soignant portera sur l'orientation du protocole (P10c-3-ii).

    Ce n'est ni un diagnostic, ni un niveau d'urgence, ni une spécialité — voir
    {@see ServiceTriageIa}. La réponse porte toujours son explication, sa confiance et ses limites
    (Rule-005, §9.3/§9.7) ; elle n'influence aucune décision (mode observation, F22).

    503 avec deux motifs distincts : aucun modèle désigné, ou modèle désigné absent de l'instance.
    """
    return service.scorer(requete)


@app.post(
    "/api/v1/triage/entrainement",
    responses={422: {"model": ReponseVolumeInsuffisant}},
    tags=["entrainement"],
)
def entrainement(requete: RequeteEntrainement) -> ReponseEntrainement:
    """Entraîne un modèle réel sur un export déjà anonymisé (P10c-3-i, F16/F17 du plan G1).

    Ne touche à RIEN de ce que `score()` sert : aucune branche de ce service ne devient
    `hybride` ici (Y10/F18) — cet endpoint ne fait que produire un `candidat` de gouvernance,
    que Laravel range dans `versions_modeles`. Sans posture réseau renforcée (F21 du plan) : même
    exposition que `/score` aujourd'hui, atteignable seulement en interne.
    """
    return entrainer(requete)
