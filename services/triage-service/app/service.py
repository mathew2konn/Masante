"""Orchestration — aujourd'hui, un refus honnête à chaque appel (F6 du G1 P10c-2-i).

Y5 : ce service n'est PAS une copie de fraud-detection. Il ne porte NI moteur de règles, ni fusion
règles+ML — les règles de triage vivent dans les protocoles Laravel, sous quatre validations
cliniques, et les redoubler ici ferait deux vérités sur le niveau d'urgence d'un citoyen (engagement
du plan P10c, §6). Sans modèle, ce service n'a donc RIEN à dire par lui-même : sa dégradation est le
refus, pas un repli sur des « règles seules » qu'il ne possède pas.
"""
from __future__ import annotations

from typing import TYPE_CHECKING

from .schemas import ReponseIndisponible

if TYPE_CHECKING:
    from .modele import ModeleTriage
    from .schemas import RequeteTriageScore


class ModeleIndisponibleError(Exception):
    """Levée quand aucun modèle n'est chargé — jamais absorbée en un score inventé."""


class ServiceTriageIa:
    def __init__(self, modele: ModeleTriage) -> None:
        self._modele = modele

    def scorer(self, requete: RequeteTriageScore) -> ReponseIndisponible:
        """Aujourd'hui : refuse toujours, honnêtement. ``requete`` est déjà validée par Pydantic —
        c'est la preuve F5 promet : le contrat, pas le jugement clinique.
        """
        if not self._modele.disponible:
            raise ModeleIndisponibleError(
                f"Aucun modèle chargé pour {requete.reference} : l'assistance IA est "
                "momentanément indisponible."
            )
        # Inatteignable tant qu'aucun modèle n'existe (P10c-3). Laissé en l'état pour que le point
        # d'accroche soit visible plutôt que déduit.
        raise NotImplementedError
