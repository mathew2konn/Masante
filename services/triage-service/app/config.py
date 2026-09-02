"""Configuration du service — DONNÉE, jamais du métier codé en dur (CDC_05).

Surchargeable par variables d'environnement préfixées ``TRIAGE_`` (ex. ``TRIAGE_MODELE_PATH=...``).

``modele_path`` reste sans repli côté service (F5/F6, inchangé par P10c-3-i, Y10/F18 du plan) :
``/api/v1/triage/score`` continue de répondre 503 à chaque appel, aucune ligne de ce fichier ne
change ça. Les réglages ci-dessous servent UNIQUEMENT `POST /api/v1/triage/entrainement` (P10c-3-i) —
la stack XGBoost/SHAP/MLflow arrive avec eux, sous l'accord écrit que constitue le plan G1.
"""
from __future__ import annotations

from pydantic_settings import BaseSettings, SettingsConfigDict


class Parametres(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="TRIAGE_", env_file=".env", extra="ignore")

    # Chemin d'un éventuel artefact de modèle. Absent aujourd'hui, et c'est le régime nominal
    # (F5/F6) : le service répond 503 avec un motif honnête, jamais un score inventé.
    modele_path: str = "models/modele_triage.json"

    # ═══ P10c-3-i — ENTRAÎNEMENT RÉEL ═══

    # Registre fichier local, aucun serveur à héberger — même mécanisme que `fraud-detection`
    # (ADR-017 §6). Passer en serveur de tracking en production = une ligne (précédent ADR-017).
    mlflow_tracking_uri: str = "file:./mlruns"

    # Dossier des artefacts de modèle entraînés (un fichier par run MLflow, nommé par son run_id —
    # ce service ne connaît pas la numérotation de gouvernance de Laravel, F17 du plan).
    modeles_dir: str = "models"

    # Défense en profondeur (F15) : Laravel vérifie déjà ce seuil avant d'appeler ce service ; ce
    # service refuse AUSSI, indépendamment. Même valeur par défaut que `masante.triage_ia.seuil_min_
    # entrainement` côté Laravel — les deux sont des configurations séparées, pas une source unique
    # partagée entre deux langages, et peuvent diverger sans que ce soit un défaut.
    seuil_min_entrainement: int = 30

    # ═══ P10c-3-ii (F27) — LE NIVEAU DE CONFIANCE DU §9.7, EN DONNÉES ═══
    #
    # « Chaque réponse IA indique ses limites et son niveau de confiance. » Le niveau se déduit de
    # la probabilité de la classe rendue ; les bornes sont un réglage d'exploitation, pas une
    # constante de code — on les remonte le jour où le modèle se révèle trop sûr de lui, sans
    # redéployer une ligne.
    #
    # Elles vivent de CE côté uniquement : Laravel enregistre ce que le service dit et n'a pas à
    # connaître la règle. Deux définitions du même seuil pourraient diverger, et l'écran de
    # gouvernance afficherait une confiance que le service n'a pas voulu dire.
    seuil_confiance_elevee: float = 0.75
    seuil_confiance_moderee: float = 0.5


parametres = Parametres()
