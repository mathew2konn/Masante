"""L'entraînement réel — `POST /api/v1/triage/entrainement` (P10c-3-i, CDC_05 §7.2/§8).

Motif direct de `fraud-detection/app/ml/entrainement.py` (MLflow fichier local, artefact +
métriques), avec trois différences qui découlent toutes de F3/F4 (P10c-2-i) et F16 (P10c-3-i) :

- **Aucun générateur synthétique** : les lignes arrivent déjà anonymisées, réelles (aussi peu
  nombreuses soient-elles), jamais fabriquées ici (Y14 du plan).
- **Multiclasse**, jamais binaire : `sous_triage` ne doit JAMAIS pouvoir être numériquement compensé
  par `sur_triage` (précédent explicite de P10c-2-i partie A).
- **`rappel_sous_triage` est nommé et loggé À PART** de la moyenne macro : un modèle peut afficher un
  bon score agrégé tout en ratant systématiquement le seul cas dangereux — l'agrégat seul ne le
  dirait jamais (F16 du plan).

``importer_lourd()`` isole les imports ML au premier appel — même motif que `fraud-detection`
(`app/ml/modele.py`) : un test qui n'entraîne jamais rien ne doit pas payer le coût de charger
xgboost/shap/mlflow.
"""
from __future__ import annotations

import os
from typing import TYPE_CHECKING

from app import config
from app.entrainement.features import NOMS_FEATURES, vecteur_features
from app.entrainement.schemas import ReponseEntrainement

if TYPE_CHECKING:
    from app.entrainement.schemas import RequeteEntrainement

LABELS: list[str] = ["adaptee", "sur_triage", "sous_triage"]
INDEX_SOUS_TRIAGE = LABELS.index("sous_triage")


class VolumeInsuffisantError(Exception):
    """Levée sous le seuil minimal (F15) — refus honnête, jamais un modèle entraîné sur trop peu."""


def entrainer(requete: RequeteEntrainement) -> ReponseEntrainement:
    import mlflow
    import numpy as np
    import pandas as pd
    import shap
    import xgboost as xgb
    from sklearn.metrics import (
        accuracy_score,
        f1_score,
        precision_score,
        recall_score,
        roc_auc_score,
    )
    from sklearn.model_selection import train_test_split

    p = config.parametres

    # ═══ GARDE, EN DOUBLE DE CELLE DE LARAVEL (F15 — « dédoublé, une couche un vecteur ») ═══
    #
    # Laravel refuse déjà avant d'appeler ce service. Ce service refuse AUSSI, indépendamment :
    # défense en profondeur, jamais de confiance aveugle dans l'appelant (motif constant du projet,
    # ex. `FiltreAntiPan` P5.4a, bornes de plausibilité P10c-1).
    if len(requete.lignes) < p.seuil_min_entrainement:
        raise VolumeInsuffisantError(
            f"{len(requete.lignes)} ligne(s) reçue(s), {p.seuil_min_entrainement} requises "
            "au minimum. Refus honnête plutôt qu'un modèle entraîné sur trop peu (F15)."
        )

    # ═══ UNE CLASSE À UN SEUL EXEMPLAIRE : REFUS MOTIVÉ, JAMAIS UN 500 OPAQUE ═══
    #
    # `train_test_split(stratify=...)` exige au moins DEUX exemplaires de chaque classe présente ;
    # en dessous il lève un `ValueError` nu, que FastAPI rendrait en 500 sans un mot exploitable.
    # Et le cas n'est pas théorique : sur les premiers retours réels la classe rare sera
    # `sous_triage` — précisément la seule dangereuse, celle qu'un agrégat ne rattrape jamais
    # (F16). Le refus la NOMME, motif des quatre validations de P10b-1 (« le refus nomme celle qui
    # manque ») : refuser sans dire laquelle laisserait chercher.
    effectifs: dict[str, int] = {}
    for ligne in requete.lignes:
        effectifs[ligne.label] = effectifs.get(ligne.label, 0) + 1

    rares = sorted(nom for nom, nb in effectifs.items() if nb < 2)
    if rares:
        detail = ", ".join(f"« {nom} » : {effectifs[nom]}" for nom in rares)
        raise VolumeInsuffisantError(
            f"Classe(s) trop peu représentée(s) pour être à la fois apprise(s) et évaluée(s) "
            f"({detail}) — il en faut au moins 2 de chaque classe présente. Refus honnête plutôt "
            "qu'un modèle dont le rappel sur cette classe serait calculé sur zéro exemple (F16)."
        )

    X = pd.DataFrame([vecteur_features(ligne) for ligne in requete.lignes], columns=NOMS_FEATURES)
    y = np.array([LABELS.index(ligne.label) for ligne in requete.lignes])

    # Stratifié : sur un petit volume, un split non stratifié pourrait laisser `sous_triage`
    # absent du test — un rappel calculé sur zéro exemple ne dirait rien.
    X_tr, X_te, y_tr, y_te = train_test_split(X, y, test_size=0.25, random_state=42, stratify=y)

    hyperparams = dict(
        n_estimators=100, max_depth=3, learning_rate=0.1,
        objective="multi:softprob", num_class=len(LABELS),
        eval_metric="mlogloss", random_state=42,
    )
    modele = xgb.XGBClassifier(**hyperparams)

    mlflow.set_tracking_uri(p.mlflow_tracking_uri)
    mlflow.set_experiment("triage-service")

    with mlflow.start_run(run_name="xgb-triage-"+requete.pays_code) as run:
        modele.fit(X_tr, y_tr)
        pred = modele.predict(X_te)
        proba = modele.predict_proba(X_te)

        # `average="macro"` : les trois classes comptent également — un modèle qui ignore
        # `sous_triage` (la classe la plus rare, en pratique) ne doit pas se cacher derrière un
        # agrégat dominé par `adaptee`.
        rappel_par_classe = recall_score(
            y_te, pred, average=None, labels=list(range(len(LABELS))), zero_division=0
        )

        metriques = {
            "exactitude": float(accuracy_score(y_te, pred)),
            "precision": float(precision_score(y_te, pred, average="macro", zero_division=0)),
            "rappel": float(recall_score(y_te, pred, average="macro", zero_division=0)),
            "f1": float(f1_score(y_te, pred, average="macro", zero_division=0)),
            # Nommée à part (F16) : la seule métrique qui dit si le modèle rate le cas dangereux.
            "rappel_sous_triage": float(rappel_par_classe[INDEX_SOUS_TRIAGE]),
        }

        # AUC multiclasse : exige `predict_proba`, et n'a de sens que si le test porte les 3 classes.
        try:
            metriques["auc"] = float(roc_auc_score(
                y_te, proba, multi_class="ovr", average="macro", labels=list(range(len(LABELS)))
            ))
        except ValueError:
            # Une classe absente du test empêche le calcul — refus PARTIEL honnête (la métrique
            # manque), pas un entraînement bloqué : sur un volume minimal, c'est attendu.
            pass

        mlflow.log_params({**hyperparams, "seuil_min_entrainement": p.seuil_min_entrainement})
        mlflow.log_param("pays_code", requete.pays_code)
        mlflow.log_param("numero_export", requete.numero_export)
        mlflow.log_param("nb_lignes_entrainement", len(X_tr))
        mlflow.log_param("nb_lignes_test", len(X_te))
        mlflow.log_metrics(metriques)

        # ═══ SHAP — IMPORTANCE GLOBALE, PAS UNE EXPLICATION PAR PRÉDICTION (P10c-3-ii) ═══
        #
        # Ce que le validateur du §9 lit avant de décider candidat→validé : quelles features pèsent
        # dans l'ensemble, pas l'explication d'un cas précis (aucune prédiction en direct n'existe
        # encore — le service ne sert toujours rien, F18/Y10).
        explainer = shap.TreeExplainer(modele)
        valeurs_shap = explainer.shap_values(X_te)
        # Compatibilité de forme, même garde que `fraud-detection` (`app/ml/modele.py`) : certaines
        # versions rendent (classes, n, features), d'autres une liste de (n, features).
        if isinstance(valeurs_shap, list):
            tableau = np.abs(np.stack(valeurs_shap)).mean(axis=(0, 1))
        elif valeurs_shap.ndim == 3:
            tableau = np.abs(valeurs_shap).mean(axis=(0, 2))
        else:
            tableau = np.abs(valeurs_shap).mean(axis=0)
        importance_globale = {nom: float(val) for nom, val in zip(NOMS_FEATURES, tableau, strict=True)}
        mlflow.log_dict(importance_globale, "importance_globale.json")

        os.makedirs(p.modeles_dir, exist_ok=True)
        chemin_modele = os.path.join(p.modeles_dir, f"triage_{run.info.run_id}.json")
        modele.save_model(chemin_modele)
        mlflow.log_artifact(chemin_modele)

        mlflow.log_dict(
            {
                "avertissement": (
                    "Entraîné sur des retours médecins réels, en nombre potentiellement très "
                    "faible : réel dans son mécanisme, pas nécessairement validé statistiquement "
                    "(limite déclarée du plan P10c-3-i)."
                ),
                "labels": LABELS,
                "features": NOMS_FEATURES,
            },
            "carte.json",
        )

        return ReponseEntrainement(
            mlflow_run_id=run.info.run_id,
            nb_lignes_entrainement=len(X_tr),
            nb_lignes_test=len(X_te),
            metriques=metriques,
        )
