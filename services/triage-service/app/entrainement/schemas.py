"""Contrat de `POST /api/v1/triage/entrainement` (P10c-3-i, révisé par P10c-3-ii F25).

Les champs cliniques viennent de ``TraitsCliniques`` (``app/schemas.py``), source unique du vecteur.
Ce qui reste déclaré ici est ce qui n'appartient qu'à l'entraînement :

- **Aucune ``reference``** : les lignes reçues ici sont déjà ANONYMISÉES par Laravel (F20 du plan) —
  il n'y a plus de pseudonyme à porter, ``reference`` désignerait encore un triage précis.
- **Aucun ``niveau_protocole``** : dans ``RequeteTriageScore`` il n'entre JAMAIS dans le vecteur de
  features (D3, P10c-2-i) — il n'a donc aucune raison d'apparaître dans un contrat d'ENTRAÎNEMENT, où
  il n'y a rien à comparer après coup. L'inclure ici romprait exactement la garantie que D3 pose côté
  service : le vecteur de features doit être identique à l'entraînement et au service (F16 du plan).
- **``label``** : la cible d'entraînement (F3, P10c-2-i).
- **Les trois faits captés par P10c-3-ii** : cibles FUTURES, jamais des features — voir la classe.
"""
from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field

from app.schemas import TraitsCliniques


class LigneEntrainement(TraitsCliniques):
    """Une ligne du jeu d'apprentissage, DÉJÀ anonymisée par Laravel avant l'envoi.

    ═══ P10c-3-ii (F25) — HÉRITE au lieu de RECOPIER ═══

    Ce schéma redéclarait les champs cliniques que ``RequeteTriageScore`` déclarait de son côté, et
    les deux prétendaient alimenter le même ``vecteur_features`` sans que rien ne les tienne
    alignés. Le G0 de P10c-3-ii a montré que l'alignement avait déjà lâché (constat Z8 :
    ``score_antecedents``, envoyé par Laravel, était écarté en silence à l'inférence). Un champ du
    vecteur est désormais déclaré **une seule fois**, dans ``TraitsCliniques``.
    """

    label: Literal["adaptee", "sur_triage", "sous_triage"]

    # ═══ CIBLES FUTURES (P10c-3-ii, F32/F36) — REÇUES, JAMAIS APPRISES DANS CE LOT ═══
    #
    # Aucune tête d'entraînement ne les consomme encore : il n'existe pas un seul diagnostic
    # enregistré au moment où ces champs naissent (constat Z9). Un modèle n'apprend que ce qu'on lui
    # a montré — entraîner sur zéro exemple ne donnerait pas un mauvais modèle, ça ne donnerait rien.
    #
    # Les déclarer maintenant a une raison précise : l'export les portera (F35), et un champ non
    # déclaré est écarté SANS UN MOT par Pydantic — c'est exactement la faute de Z8, qu'on ne
    # rejoue pas trois lignes plus bas.
    #
    # Ils vivent ICI et non dans ``TraitsCliniques`` parce que ce sont des CIBLES : les mettre en
    # entrée ferait prédire le diagnostic à partir du diagnostic (F36, vérifié par un vecteur).
    niveau_reel: str | None = Field(None, description="Niveau que le soignant aurait retenu (§5.3)")
    maladie_code: str | None = Field(None, description="Diagnostic final, code du référentiel P6.8c")
    specialite_code: str | None = Field(None, description="Spécialité ayant réellement pris en charge")


class RequeteEntrainement(BaseModel):
    """Un export anonymisé complet, prêt à entraîner (F15/F17 du plan)."""

    pays_code: str
    numero_export: int
    lignes: list[LigneEntrainement]


class ReponseEntrainement(BaseModel):
    """Ce que Laravel range dans `versions_modeles`/`metriques_modeles` (F17).

    ``numero_version`` n'existe PAS ici : c'est Laravel qui numérote (registre de gouvernance,
    F17), ce service ne connaît que son propre run MLflow.
    """

    mlflow_run_id: str
    nb_lignes_entrainement: int
    nb_lignes_test: int
    metriques: dict[str, float]


class ReponseVolumeInsuffisant(BaseModel):
    """Refus F15, double garde : ce service refuse aussi, indépendamment de Laravel."""

    motif: Literal["volume_insuffisant"]
    message: str
