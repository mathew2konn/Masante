"""Interface du modèle — SANS modèle (F5 du G1 P10c-2-i).

Aucun XGBoost/SHAP/MLflow ici : ce service n'a aucun artefact à charger aujourd'hui, et en installer
serait de la mise en scène (§2.6 : aucune dépendance sans accord écrit, pour des paquets lourds dont
rien ne se sert). ``disponible`` reste ``False`` de façon permanente tant que P10c-3 n'a pas livré un
modèle réel et l'accord qui va avec — ce n'est pas une panne, c'est l'état nominal de cet incrément.
"""
from __future__ import annotations


class ModeleTriage:
    def __init__(self) -> None:
        self.disponible: bool = False


# Singleton — même motif que fraud-detection, pour que l'endroit où un futur modèle s'accrocherait
# soit déjà celui qu'on regarde.
modele = ModeleTriage()
