"""Configuration du service — DONNÉE, jamais du métier codé en dur (CDC_05).

Surchargeable par variables d'environnement préfixées ``TRIAGE_`` (ex. ``TRIAGE_MODELE_PATH=...``).

Absent délibérément (F5 du G1 P10c-2-i) : tout ce qui touche XGBoost/SHAP/MLflow. Ce service n'a
aucun modèle à charger aujourd'hui ; ces réglages arriveront avec lui, en P10c-3, sous accord écrit
(§2.6) — les installer maintenant serait de la mise en scène pour une image plus lourde sans raison.
"""
from __future__ import annotations

from pydantic_settings import BaseSettings, SettingsConfigDict


class Parametres(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="TRIAGE_", env_file=".env", extra="ignore")

    # Chemin d'un éventuel artefact de modèle. Absent aujourd'hui, et c'est le régime nominal
    # (F5/F6) : le service répond 503 avec un motif honnête, jamais un score inventé.
    modele_path: str = "models/modele_triage.json"


parametres = Parametres()
