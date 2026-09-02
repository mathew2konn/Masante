"""Orchestration de `/api/v1/triage/score` (P10c-3-ii, F22/F27).

═══ CE QUE CE SERVICE DIT, ET CE QU'IL NE DIT PAS ═══

Il répond à une seule question : **« un soignant jugera-t-il cette orientation adaptée, trop haute
ou trop basse ? »** — un avis sur le travail du PROTOCOLE, jamais sur le patient. Il ne nomme aucune
maladie (interdit absolu, CDC_00 §4 ; l'exemple de réponse du §5.2 n'en comporte lui-même aucune),
ne propose aucune spécialité (elle vient du référentiel gouverné, ADR-040) et ne fixe aucun niveau
(il vient du protocole signé, P10b-1).

Y5 tient : aucune règle de triage n'est réimplémentée ici. Les protocoles Laravel, sous quatre
validations cliniques, restent l'unique source du niveau — les redoubler ferait deux vérités sur
l'urgence d'un citoyen.

═══ LA RÉPONSE N'INFLUENCE RIEN (F22) ═══

CDC_08 §3 place le raisonnement IA au **sixième et dernier** rang, « uniquement pour compléter […]
jamais pour contredire un protocole officiel ». Ce que rend ce service est enregistré et comparé
après coup ; il n'entre dans aucune décision, et le mode qui le désigne côté Laravel s'appelle
`observation` pour cette raison précise.

═══ DÉGRADATION HONNÊTE, DEUX MOTIFS QUI NE SE CONFONDENT PAS (F6/F23) ═══

Sans modèle demandé → `modele_indisponible` (régime nominal tant qu'aucune version n'est `actif`).
Modèle demandé mais absent du disque → `modele_absent_du_service`. C'est Laravel qui absorbe et rend
le triage complet ; jamais ce service qui prétend décider.
"""
from __future__ import annotations

from typing import TYPE_CHECKING, Literal

from app import config
from app.entrainement.entrainer import LABELS
from app.entrainement.features import vecteur_features
from app.schemas import ReponseTriageScore

if TYPE_CHECKING:
    from app.modele import RegistreModeles
    from app.schemas import RequeteTriageScore

# ═══ §9.7 — « CHAQUE RÉPONSE IA INDIQUE SES LIMITES ET SON NIVEAU DE CONFIANCE » ═══
#
# Cette phrase n'est pas décorative : c'est la seule chose qui empêche un lecteur pressé de prendre
# une probabilité pour un fait. Elle dit les trois limites réelles, dans l'ordre où elles trompent :
# ce n'est pas un diagnostic, ce n'est pas le niveau d'urgence, et le modèle a peu appris.
LIMITES = (
    "Avis sur l'orientation proposée par le protocole, jamais un diagnostic et jamais un niveau "
    "d'urgence. N'entre dans aucune décision de soins (mode observation). Modèle entraîné sur un "
    "volume de retours réels potentiellement faible : réel dans son mécanisme, pas validé "
    "statistiquement."
)


class ModeleIndisponibleError(Exception):
    """Aucun modèle n'est en service — jamais absorbée en un score inventé."""


class ServiceTriageIa:
    def __init__(self, registre: RegistreModeles) -> None:
        self._registre = registre

    def scorer(self, requete: RequeteTriageScore) -> ReponseTriageScore:
        if requete.modele_attendu is None:
            raise ModeleIndisponibleError(
                f"Aucun modèle actif n'est désigné pour {requete.reference} : l'assistance IA est "
                "momentanément indisponible."
            )

        # ═══ L'ORDRE COMPTE : LE CONTRAT AVANT L'ARTEFACT ═══
        #
        # Le vecteur est composé d'abord, et c'est lui qui refuse une tranche d'âge inconnue
        # (`BandeAgeInconnueError` → 422). Le faire après le chargement rendrait ce refus dépendant
        # de la présence d'un fichier sur le disque : une divergence de bornes entre la config
        # Laravel et ce service serait alors masquée par un 503 sur une instance qui n'a pas
        # l'artefact — un défaut de configuration déguisé en panne d'exploitation.
        vecteur = vecteur_features(requete)

        # Lève `ModeleAbsentError` si le registre désigne un artefact que cette instance n'a pas.
        # Volontairement NON rattrapée ici : la confondre avec l'indisponibilité nominale masquerait
        # une panne d'exploitation réelle (F23).
        charge = self._registre.obtenir(requete.modele_attendu)

        probabilites, facteurs = charge.predire(vecteur)

        indice = max(range(len(probabilites)), key=lambda i: probabilites[i])

        return ReponseTriageScore(
            # Le run RÉELLEMENT utilisé, pas celui demandé. Les deux coïncident forcément
            # aujourd'hui — mais journaliser l'espéré plutôt que l'effectif est la façon dont une
            # traçabilité cesse d'en être une (§9.2).
            modele_version=charge.run_id,
            classe_predite=LABELS[indice],  # type: ignore[arg-type]
            probabilites={nom: round(p, 6) for nom, p in zip(LABELS, probabilites, strict=True)},
            facteurs=facteurs,
            confiance=self._confiance(probabilites[indice]),
            limites=LIMITES,
        )

    @staticmethod
    def _confiance(probabilite: float) -> Literal["elevee", "moderee", "faible"]:
        """Le niveau de confiance du §9.7 — seuils en DONNÉES, jamais codés en dur.

        Ils vivent de ce côté UNIQUEMENT : Laravel enregistre ce qu'il reçoit et n'a pas à connaître
        la règle. Deux définitions du même seuil pourraient diverger, et l'écran de gouvernance
        afficherait alors une confiance que le service n'a pas voulu dire.
        """
        p = config.parametres
        if probabilite >= p.seuil_confiance_elevee:
            return "elevee"
        if probabilite >= p.seuil_confiance_moderee:
            return "moderee"

        return "faible"
