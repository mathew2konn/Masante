"""Orchestration : signal → règles → (ML si dispo) → fusion → résultat explicable.

DÉTECTION SEULE : ce service ne gèle rien, ne corrige rien. Il note et explique ; un humain décide
(CDC_00 §4, CDC_05 §9.1). La garde temps-réel transactionnelle qui bloque/gèle reste côté Java (P5.3b-2).
"""
from __future__ import annotations

from typing import TYPE_CHECKING

from .domain.features import vecteur_features
from .domain.fusion import fusionner
from .domain.regles import evaluer_regles
from .schemas import ReponseScan, ResultatFraude, ResumeScan

if TYPE_CHECKING:
    from .config import Parametres
    from .ml.modele import ModeleFraude
    from .schemas import SignalFacturation


class ServiceFraude:
    def __init__(self, modele: ModeleFraude, params: Parametres) -> None:
        self._modele = modele
        self._p = params

    def scorer(self, signal: SignalFacturation) -> ResultatFraude:
        res_regles = evaluer_regles(signal, self._p)
        if self._modele.disponible:
            proba, facteurs = self._modele.predire(vecteur_features(signal))
            mode = "hybride"
        else:
            proba, facteurs, mode = None, [], "regles_seules"
        return fusionner(signal, res_regles, proba, facteurs, self._p, mode)

    def scorer_lot(self, signaux: list[SignalFacturation]) -> ReponseScan:
        resultats = [self.scorer(s) for s in signaux]
        mode = "hybride" if self._modele.disponible else "regles_seules"
        resume = ResumeScan(
            total=len(resultats),
            normal=sum(1 for r in resultats if r.niveau == "NORMAL"),
            suspect=sum(1 for r in resultats if r.niveau == "SUSPECT"),
            tres_suspect=sum(1 for r in resultats if r.niveau == "TRES_SUSPECT"),
            mode=mode,
        )
        return ReponseScan(resume=resume, resultats=resultats)
