"""Contrat d'API (Pydantic → OpenAPI).

Vecteur d'entrée = §5.2 tel que P10c-1 l'a rendu collectable (constantes, durée, intensité,
grossesse) + les codes de symptômes + âge/sexe. AUCUNE identité (D7/F9) : ``reference`` est un
identifiant pseudonyme (``triage:1234``), jamais un ``membre_id``, un NIS ou un nom.

``niveau_protocole`` est reçu pour comparaison APRÈS inférence (D3 du G1 P10c-2-i) — il n'entre
JAMAIS dans le vecteur de features. Aucun modèle n'existe encore pour s'en servir ; le champ est
défini maintenant pour que le contrat n'ait pas à changer le jour où P10c-3 l'active.
"""
from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class Constantes(BaseModel):
    """Constantes vitales du §5.2, telles que P10c-1 les gouverne. Toutes facultatives."""

    temperature: float | None = Field(None, description="°C")
    pouls: float | None = Field(None, description="battements/min")
    saturation_o2: float | None = Field(None, description="%")
    tension_systolique: float | None = Field(None, description="mmHg")
    tension_diastolique: float | None = Field(None, description="mmHg")
    poids: float | None = Field(None, description="kg")


class RequeteTriageScore(BaseModel):
    """Une demande de score pour un triage — AUCUNE identité, une référence pseudonyme seulement."""

    reference: str = Field(
        ..., description="Référence pseudonyme (ex. « triage:1234 »), jamais un identifiant de compte")
    age: int | None = Field(None, ge=0, le=130)
    sexe: Literal["M", "F"] | None = None
    # Identifiants numériques (P10a/P10b-3-i : les symptômes n'ont pas de code national — un
    # identifiant technique ne veut rien dire hors de cette base, mais c'est la même base des deux
    # côtés de cet appel, et le prétendre autrement inventerait un vocabulaire qui n'existe pas).
    symptomes: list[int] = Field(
        default_factory=list, description="Identifiants de symptômes (référentiel Laravel)")
    constantes: Constantes = Field(default_factory=lambda: Constantes())
    duree_jours: int | None = Field(
        None, ge=0, description="Durée des symptômes (§5.2), depuis reponse.duree_jours")
    intensite: int | None = Field(None, ge=1, le=10, description="Douleur (§5.2), depuis reponse.intensite")
    grossesse: bool | None = None
    niveau_protocole: str | None = Field(
        None,
        description="Décision du protocole, pour comparaison APRÈS inférence — jamais une feature (D3)")

    model_config = {
        "json_schema_extra": {
            "example": {
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
        }
    }


class ReponseIndisponible(BaseModel):
    """Corps du refus honnête (F6) : jamais de score inventé faute de modèle."""

    motif: Literal["modele_indisponible"]
    message: str
