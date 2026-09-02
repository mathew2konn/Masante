"""Chargement des artefacts de modèle (P10c-3-ii, F23).

═══ CE SERVICE NE CHOISIT PAS QUEL MODÈLE RÉPOND ═══

C'est le registre de gouvernance Laravel (`versions_modeles`) qui porte le statut `actif`, parce que
c'est là que vivent la permission, le quatre-yeux et la date de validation clinique du §9.6 — un
tracker d'expériences n'a aucune de ces notions, et un dossier de fichiers encore moins.

Ce module ne fait donc qu'obéir : on lui demande un `run_id`, il charge CET artefact. S'il ne l'a
pas, il **refuse** (`ModeleAbsentError`). Il ne retombe JAMAIS sur un autre fichier, fût-il le plus
récent : la base dirait « le modèle actif est X » pendant que la réponse viendrait de Y, et deux
vérités sur ce qui a produit une prédiction médicale est ce que ce projet refuse depuis P6.6a.

═══ POURQUOI LE REFUS A SON PROPRE MOTIF ═══

« Aucun modèle en service » (nominal tant qu'aucune version n'est `actif`) et « le registre désigne
un modèle que cette instance n'a pas » sont deux situations sans rapport. La seconde est un vrai
défaut d'exploitation — les artefacts vivent sur le disque du service et non dans MinIO (§10, limite
annoncée) : en multi-instance, une instance peut ne pas avoir le fichier. Le noyer dans le premier
motif rendrait cette panne indiscernable du régime normal.

═══ CACHE ═══

Un artefact chargé et son explainer SHAP le restent : construire un ``TreeExplainer`` à chaque
requête coûterait à chaque triage ce qu'on ne paie qu'une fois. Le cache est indexé par `run_id`, ce
qui rend le rollback (F24) gratuit — réactiver une version déjà chargée ne relit rien.
"""
from __future__ import annotations

import os
import threading
from typing import Any

from app import config


class ModeleAbsentError(Exception):
    """Le registre désigne un run que cette instance n'a pas sur son disque (F23)."""


class ModeleCharge:
    """Un artefact prêt à servir : le booster et son explainer, chargés une fois."""

    def __init__(self, run_id: str, booster: Any, explainer: Any) -> None:
        self.run_id = run_id
        self.booster = booster
        self.explainer = explainer

    def predire(self, vecteur: dict[str, float]) -> tuple[list[float], list[dict[str, float | str]]]:
        """Rend les probabilités des trois classes et les facteurs SHAP de CETTE prédiction.

        Prend un vecteur DÉJÀ construit, jamais des traits bruts : c'est l'appelant qui le compose,
        pour que la validation du contrat (une tranche d'âge inconnue, par exemple) se produise
        AVANT le chargement d'un artefact. Une requête malformée doit se voir refuser en 422 sans
        dépendre de la présence d'un fichier sur le disque.

        L'explication est produite ICI, en même temps que la prédiction, et non reconstruite après
        coup : une explication recalculée plus tard sur un autre artefact expliquerait autre chose
        que ce qui a été rendu. Rule-005 exige qu'elle accompagne la décision, pas qu'elle lui
        ressemble.
        """
        import numpy as np
        import pandas as pd

        from app.entrainement.features import NOMS_FEATURES

        ligne = pd.DataFrame([vecteur], columns=NOMS_FEATURES)

        probabilites = [float(p) for p in self.booster.predict_proba(ligne)[0]]

        valeurs = self.explainer.shap_values(ligne)
        # Compatibilité de forme, même garde que `fraud-detection` et que l'entraînement : selon la
        # version, SHAP rend une liste par classe ou un tableau (n, features, classes).
        tableau = np.stack(valeurs) if isinstance(valeurs, list) else np.asarray(valeurs)
        if tableau.ndim == 3:
            # On agrège sur les classes : l'explication porte sur la prédiction rendue, et un
            # facteur qui pèse contre une classe pèse pour une autre — l'amplitude est ce qui
            # intéresse un relecteur, pas le signe classe par classe.
            poids = np.abs(tableau).mean(axis=tuple(a for a in range(tableau.ndim) if a != 1))
        else:
            poids = np.abs(tableau).reshape(-1)

        facteurs: list[dict[str, float | str]] = [
            {"feature": nom, "poids": round(float(valeur), 6)}
            for nom, valeur in zip(NOMS_FEATURES, poids, strict=True)
        ]
        facteurs.sort(key=lambda f: float(f["poids"]), reverse=True)

        return probabilites, facteurs


class RegistreModeles:
    """Les artefacts chargés, indexés par `run_id`. Ne décide jamais lequel sert (F23)."""

    def __init__(self) -> None:
        self._charges: dict[str, ModeleCharge] = {}
        self._verrou = threading.Lock()

    @property
    def disponible(self) -> bool:
        """Vrai dès qu'un artefact est chargé. `/ready` s'en sert pour dire l'état, pas pour choisir."""
        return bool(self._charges)

    def runs_charges(self) -> list[str]:
        return sorted(self._charges)

    def chemin(self, run_id: str) -> str:
        return os.path.join(config.parametres.modeles_dir, f"triage_{run_id}.json")

    def obtenir(self, run_id: str) -> ModeleCharge:
        """Rend l'artefact demandé, en le chargeant au besoin. Lève s'il est absent — jamais de repli."""
        charge = self._charges.get(run_id)
        if charge is not None:
            return charge

        # Le verrou évite que deux requêtes simultanées reconstruisent le même explainer ; il ne
        # protège aucune décision, seulement un coût.
        with self._verrou:
            charge = self._charges.get(run_id)
            if charge is not None:
                return charge

            chemin = self.chemin(run_id)
            if not os.path.isfile(chemin):
                raise ModeleAbsentError(
                    f"Le modèle « {run_id} » désigné par le registre est absent de cette instance "
                    f"({chemin}). Aucun autre artefact n'est servi à sa place."
                )

            import shap
            import xgboost as xgb

            booster = xgb.XGBClassifier()
            booster.load_model(chemin)
            self._charges[run_id] = ModeleCharge(run_id, booster, shap.TreeExplainer(booster))

            return self._charges[run_id]


# Singleton — même motif que `fraud-detection`, pour que l'endroit où un modèle s'accroche soit
# celui qu'on regarde.
modele = RegistreModeles()
