"""Script d'entraînement — `python -m app.ml.entrainement`.

Entraîne XGBoost sur les données SYNTHÉTIQUES, journalise params + métriques dans MLflow (registre
FICHIER local `file:./mlruns`, aucun serveur à héberger), et sauvegarde l'artefact + une carte de
métriques. La carte porte un AVERTISSEMENT explicite : modèle de démonstration, non validé cliniquement.
"""
from __future__ import annotations

import json
import os

from .. import config
from .donnees_synthetiques import generer


def entrainer() -> dict[str, float]:
    import mlflow
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
    mlflow.set_tracking_uri(p.mlflow_tracking_uri)
    mlflow.set_experiment("fraud-detection")

    X, y = generer()
    X_tr, X_te, y_tr, y_te = train_test_split(X, y, test_size=0.25, random_state=42, stratify=y)

    hyperparams = dict(
        n_estimators=200, max_depth=4, learning_rate=0.1,
        subsample=0.9, eval_metric="logloss", random_state=42,
    )
    modele = xgb.XGBClassifier(**hyperparams)

    with mlflow.start_run(run_name="xgb-synthetique"):
        modele.fit(X_tr, y_tr)
        pred = modele.predict(X_te)
        proba = modele.predict_proba(X_te)[:, 1]
        metriques = {
            "exactitude": float(accuracy_score(y_te, pred)),
            "precision": float(precision_score(y_te, pred)),
            "rappel": float(recall_score(y_te, pred)),
            "f1": float(f1_score(y_te, pred)),
            "auc": float(roc_auc_score(y_te, proba)),
        }
        mlflow.log_params(hyperparams)
        mlflow.log_param("jeu_de_donnees", "SYNTHETIQUE_DEMO")
        mlflow.log_metrics(metriques)

        os.makedirs(os.path.dirname(p.modele_path) or ".", exist_ok=True)
        modele.save_model(p.modele_path)
        mlflow.log_artifact(p.modele_path)

        carte = {
            "modele": "fraud-xgb-synthetique",
            "version": "1.0.0",
            "jeu_de_donnees": "SYNTHETIQUE_DEMO",
            "features": list(X.columns),
            "metriques": metriques,
            "statut": "candidat",
            "avertissement": (
                "Modèle entraîné sur des données SYNTHÉTIQUES de démonstration — "
                "NON validé cliniquement, ne reflète aucune donnée réelle de production."
            ),
        }
        carte_path = os.path.join(os.path.dirname(p.modele_path) or ".", "metriques.json")
        with open(carte_path, "w", encoding="utf-8") as f:
            json.dump(carte, f, ensure_ascii=False, indent=2)
        mlflow.log_artifact(carte_path)

    print("Entraînement terminé (données SYNTHÉTIQUES). Métriques :", metriques)
    return metriques


if __name__ == "__main__":
    entrainer()
