"""Chargement du modèle XGBoost + explicabilité SHAP.

Dégradation gracieuse (CDC_05 §1.7/§10) : si l'artefact est absent, ``disponible`` reste False et le
service bascule en règles seules. Les imports lourds (xgboost/shap/pandas/numpy) sont paresseux pour
garder un ``import modele`` bon marché (les tests unitaires purs ne les chargent pas).
"""
from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Any

from ..domain.features import NOMS_FEATURES


@dataclass(frozen=True)
class FacteurSHAP:
    feature: str
    contribution: float
    valeur: float


class ModeleFraude:
    def __init__(self) -> None:
        self.disponible: bool = False
        self._model: Any = None
        self._explainer: Any = None

    def charger(self, chemin: str) -> None:
        if not os.path.exists(chemin):
            self.disponible = False
            return
        import shap
        import xgboost as xgb

        modele = xgb.XGBClassifier()
        modele.load_model(chemin)
        self._model = modele
        self._explainer = shap.TreeExplainer(modele)
        self.disponible = True

    def predire(self, features: dict[str, float]) -> tuple[float, list[FacteurSHAP]]:
        if not self.disponible or self._model is None:
            raise RuntimeError("Modèle non chargé")
        import numpy as np
        import pandas as pd

        ligne = [[features[n] for n in NOMS_FEATURES]]
        df = pd.DataFrame(ligne, columns=NOMS_FEATURES)
        proba = float(self._model.predict_proba(df)[0, 1])

        valeurs = np.asarray(self._explainer.shap_values(df))
        if valeurs.ndim == 3:  # certaines versions renvoient (classes, n, features)
            valeurs = valeurs[-1]
        contributions = valeurs[0]
        facteurs = [
            FacteurSHAP(NOMS_FEATURES[i], float(contributions[i]), float(df.iat[0, i]))
            for i in range(len(NOMS_FEATURES))
        ]
        facteurs.sort(key=lambda f: abs(f.contribution), reverse=True)
        return proba, facteurs


# Singleton chargé au démarrage (lifespan FastAPI).
modele = ModeleFraude()
