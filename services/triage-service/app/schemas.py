"""Contrat d'API (Pydantic → OpenAPI).

Vecteur d'entrée = §5.2 tel que P10c-1 l'a rendu collectable (constantes, durée, intensité,
grossesse) + les codes de symptômes + tranche d'âge/sexe. AUCUNE identité (D7/F9) : ``reference``
est un identifiant pseudonyme (``triage:1234``), jamais un ``membre_id``, un NIS ou un nom.

═══ P10c-3-ii (F25) — ``TraitsCliniques`` EST LA SOURCE UNIQUE DU VECTEUR ═══

Jusqu'ici, ``RequeteTriageScore`` (inférence) et ``LigneEntrainement`` (entraînement) déclaraient
CHACUNE les champs du vecteur, et prétendaient toutes deux alimenter le même ``vecteur_features``.
Rien de structurel ne les tenait alignées — seulement la discipline. Le G0 de P10c-3-ii a montré
que la discipline avait déjà lâché : ``score_antecedents``, envoyé par Laravel depuis F14, n'était
pas déclaré ici et Pydantic l'écartait **en silence** (constat Z8). Le jour où le modèle tourne, la
feature aurait valu ``NaN`` à l'inférence alors qu'elle existait à l'entraînement — le décalage
train/serve exact que F16 prétendait avoir fermé.

Les deux schémas HÉRITENT désormais de cette base : un champ du vecteur est déclaré **une seule
fois**, et l'oubli devient inexprimable au lieu d'être interdit par un commentaire.

Corollaire non moins important : ``niveau_protocole`` et ``reference`` restent **hors** de la base.
Ce n'est plus une promesse (D3 de P10c-2-i), c'est une propriété du code — ``vecteur_features`` ne
reçoit qu'un ``TraitsCliniques`` et ne PEUT donc pas les lire.
"""
from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class Constantes(BaseModel):
    """Constantes vitales du §5.2, telles que P10c-1 les gouverne. Toutes facultatives."""

    temperature: float | None = Field(None, description="°C")
    pouls: float | None = Field(None, description="battements/min")
    saturation_o2: float | None = Field(None, description="%")
    tension_systolique: float | None = Field(None, description="mmHg")
    tension_diastolique: float | None = Field(None, description="mmHg")
    poids: float | None = Field(None, description="kg")


class TraitsCliniques(BaseModel):
    """Les traits du patient qui composent le vecteur — et RIEN d'autre (F25).

    Tout champ ajouté ici entre dans le vecteur des DEUX côtés à la fois ; tout champ qui ne doit
    pas y entrer se déclare sur la sous-classe concernée, jamais ici.

    ``bande_age`` et non ``age`` (F26, décision propriétaire) : le modèle a appris sur des tranches,
    parce que l'export les généralise pour casser le croisement âge exact × sexe × date qui
    ré-identifie une personne (F20/CDC_13 §12). Lui envoyer un âge exact serait lui parler dans une
    autre échelle — il répondrait, et se tromperait sans erreur visible. Les bornes vivent d'un seul
    côté, la config Laravel ; ce service ne connaît que l'ORDRE des étiquettes, et refuse
    bruyamment celles qu'il ignore.

    Gain non cherché : l'âge exact ne sort plus du backend (minimisation §9.4).
    """

    bande_age: str | None = Field(
        None, description="Âge généralisé en tranche (ex. « 25-44 »), jamais l'âge exact (F26)")
    sexe: Literal["M", "F"] | None = None
    # Identifiants numériques (P10a/P10b-3-i : les symptômes n'ont pas de code national — un
    # identifiant technique ne veut rien dire hors de cette base, mais c'est la même base des deux
    # côtés de cet appel, et le prétendre autrement inventerait un vocabulaire qui n'existe pas).
    symptomes: list[int] = Field(
        default_factory=list, description="Identifiants de symptômes (référentiel Laravel)")
    constantes: Constantes = Field(default_factory=lambda: Constantes())
    duree_jours: int | None = Field(
        None, ge=0, description="Durée des symptômes (§5.2), depuis reponse.duree_jours")
    intensite: int | None = Field(None, ge=1, le=10, description="Douleur (§5.2), depuis reponse.intensite")
    grossesse: bool | None = None
    score_antecedents: int | None = Field(
        None, ge=0, le=20, description="Valeur bornée de P10b-3-ii (F14) — déclarée ICI depuis Z8")


class RequeteTriageScore(TraitsCliniques):
    """Une demande de score — AUCUNE identité, une référence pseudonyme seulement."""

    reference: str = Field(
        ..., description="Référence pseudonyme (ex. « triage:1234 »), jamais un identifiant de compte")

    # F23 — LE REGISTRE DE GOUVERNANCE DÉCIDE QUEL MODÈLE RÉPOND, PAS CE SERVICE.
    #
    # Laravel envoie le `mlflow_run_id` de la version `actif`. Ce service charge CELUI-LÀ, et
    # refuse s'il ne l'a pas (`modele_absent_du_service`) : servir un autre artefact ferait dire à
    # la base « l'actif est X » pendant que la réponse viendrait de Y — deux vérités sur ce qui a
    # produit une prédiction médicale.
    modele_attendu: str | None = Field(
        None, description="Identifiant de run du modèle actif, décidé par le registre Laravel (F23)")

    niveau_protocole: str | None = Field(
        None,
        description="Décision du protocole, pour comparaison APRÈS inférence — jamais une feature (D3)")

    model_config = {
        "json_schema_extra": {
            "example": {
                "reference": "triage:1234",
                "modele_attendu": "fc4b731ea19a46a882d9d9885fa5bc3d",
                "bande_age": "25-44",
                "sexe": "F",
                "symptomes": [12, 47],
                "constantes": {"temperature": 38.9, "pouls": 96},
                "duree_jours": 2,
                "intensite": 5,
                "grossesse": False,
                "score_antecedents": 6,
                "niveau_protocole": "modere",
            }
        }
    }


class ReponseTriageScore(BaseModel):
    """Ce que rend une prédiction réelle (P10c-3-ii, F27).

    ═══ AUCUN CHAMP N'EST FACULTATIF, ET C'EST LA RÈGLE 005 ═══

    « Aucune IA ne prend de décision médicale sans expliquer son raisonnement. Chaque
    recommandation précise : les données utilisées, les protocoles appliqués, le score de confiance
    et les limites » (§1.1). Une explication vide n'est pas une explication dégradée, c'est une
    violation — d'où des champs REQUIS plutôt que nullables, et un vecteur qui casse le build si
    l'un d'eux manque (§11 : « toute réponse doit contenir une explication non vide »).

    ═══ CE QUE CETTE RÉPONSE N'EST PAS ═══

    Ni un niveau de triage, ni un diagnostic. ``classe_predite`` répond à « un soignant jugera-t-il
    cette orientation adaptée ? » — c'est un avis sur le travail du PROTOCOLE, pas sur le patient.
    Elle n'entre dans aucune décision (mode `observation`, F22) : CDC_08 §3 place le raisonnement IA
    au sixième et dernier rang, « jamais pour contredire un protocole officiel ».
    """

    modele_version: str = Field(..., description="Le run réellement utilisé — jamais celui espéré")
    classe_predite: Literal["adaptee", "sur_triage", "sous_triage"]
    probabilites: dict[str, float] = Field(..., description="Les trois classes, toujours les trois")
    facteurs: list[dict[str, float | str]] = Field(
        ..., description="Facteurs SHAP de CETTE prédiction (§9.3), jamais une importance globale")
    confiance: Literal["elevee", "moderee", "faible"]
    limites: str = Field(..., min_length=1, description="§9.7 — jamais vide")


class ReponseIndisponible(BaseModel):
    """Corps du refus honnête (F6/F23) : jamais de score inventé, jamais un autre modèle.

    Deux motifs, délibérément distincts. ``modele_indisponible`` : aucun modèle n'est demandé ou
    aucun n'est en service — le régime nominal tant qu'aucune version n'est `actif`.
    ``modele_absent_du_service`` : le registre désigne un modèle que CETTE instance n'a pas sur son
    disque. Le second est un vrai problème d'exploitation (§10 : les artefacts devraient vivre dans
    MinIO) ; le noyer dans le premier le rendrait invisible.
    """

    motif: Literal["modele_indisponible", "modele_absent_du_service"]
    message: str
