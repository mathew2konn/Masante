"""Fusion hybride règles + ML → résultat explicable (Rule-005).

Principe (CDC_05 §1.6) : les RÈGLES font autorité (une incohérence dure escalade quoi que dise le ML),
le ML est INDICATIF. Toute sortie porte : données utilisées, score, confiance, limites. L'explication
n'est JAMAIS vide. Détection SEULE : aucune action automatique (§9.1).
"""
from __future__ import annotations

from typing import TYPE_CHECKING

from ..schemas import FacteurMl, RegleDeclenchee, ResultatFraude

if TYPE_CHECKING:
    from ..config import Parametres
    from ..ml.modele import FacteurSHAP
    from ..schemas import SignalFacturation
    from .regles import ResultatRegles

_LIMITES_BASE = (
    "Aide à la décision — détection seule. Modèle entraîné sur données synthétiques de "
    "démonstration, non validé cliniquement. Aucune action automatique n'est prise ; "
    "toute suite relève d'une revue humaine."
)


def fusionner(
    signal: SignalFacturation,
    res_regles: ResultatRegles,
    proba_ml: float | None,
    facteurs_ml: list[FacteurSHAP],
    p: Parametres,
    mode: str,
) -> ResultatFraude:
    score_regles = min(100, res_regles.score)

    if mode == "hybride" and proba_ml is not None:
        score = round(p.poids_regles * score_regles + p.poids_ml * proba_ml * 100)
    else:
        score = score_regles
    score = max(0, min(100, int(score)))

    # Niveau : les incohérences dures escaladent (règles font autorité)
    if res_regles.force_escalade:
        niveau = "TRES_SUSPECT"
        score = max(score, p.seuil_tres_suspect)
    elif score >= p.seuil_tres_suspect:
        niveau = "TRES_SUSPECT"
    elif score >= p.seuil_suspect:
        niveau = "SUSPECT"
    else:
        niveau = "NORMAL"

    # Explication — jamais vide (Rule-005)
    explication: list[str] = [
        f"{r.libelle} (observé={r.valeur_observee:g}, seuil={r.seuil:g})" for r in res_regles.regles
    ]
    if mode == "hybride" and facteurs_ml:
        for f in facteurs_ml[:3]:
            sens = "augmente" if f.contribution > 0 else "diminue"
            explication.append(
                f"Facteur ML « {f.feature} »={f.valeur:g} {sens} le risque (SHAP={f.contribution:+.3f})"
            )
    if not explication:
        explication.append("Aucun signal de fraude détecté sur les critères évalués.")

    # Confiance
    if mode != "hybride" or proba_ml is None:
        confiance = "reduite"
    else:
        ml_haut = proba_ml >= 0.5
        regles_hautes = score_regles >= p.seuil_suspect
        confiance = "elevee" if ml_haut == regles_hautes else "moyenne"

    limites = _LIMITES_BASE
    if mode != "hybride":
        limites = (
            "Assistance ML momentanément indisponible : évaluation par règles déterministes seules. "
            + limites
        )

    return ResultatFraude(
        reference=signal.reference,
        niveau=niveau,
        score=score,
        probabilite_ml=proba_ml,
        mode=mode,
        regles_declenchees=[
            RegleDeclenchee(
                code=r.code, libelle=r.libelle, poids=r.poids,
                valeur_observee=r.valeur_observee, seuil=r.seuil,
            )
            for r in res_regles.regles
        ],
        facteurs_ml=[
            FacteurMl(feature=f.feature, contribution=f.contribution, valeur=f.valeur)
            for f in (facteurs_ml[:5] if mode == "hybride" else [])
        ],
        explication=explication,
        confiance=confiance,
        limites=limites,
        action="AUCUNE — détection seule, revue humaine requise",
    )
