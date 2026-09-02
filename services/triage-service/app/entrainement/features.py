"""Le vecteur de features — SOURCE UNIQUE, entraînement et (futur) service (F16 du plan P10c-3-i).

Motif direct de `fraud-detection/app/domain/features.py` : ``NOMS_FEATURES`` est importé à la fois
par l'entraînement et par le service, pour qu'aucun des deux ne puisse dériver de l'autre — c'est ce
qui répond à Y4 (le décalage train/serve) : le vecteur ne dépend d'AUCUNE version de protocole
publiée, seulement de l'intake clinique lui-même (§5.1), donc republier un protocole ne le fait
jamais bouger.

``nb_symptomes`` REMPLACE la liste des symptômes : un XGBoost prend des colonnes numériques stables,
pas une liste de longueur variable. Encoder chaque symptôme en colonne (« multi-hot ») exigerait de
figer un vocabulaire versionné — un second problème de gouvernance que cet incrément n'ouvre pas.
Coût assumé et dit (limite du plan) : SHAP pourra dire que « les symptômes » pèsent, jamais lequel.
Même reduction, même raison, que ``score_antecedents`` pour les antécédents (F14).
"""
from __future__ import annotations

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from app.schemas import TraitsCliniques

NOMS_FEATURES: list[str] = [
    "age_bande_ordinale",
    "sexe_f",
    "nb_symptomes",
    "temperature",
    "pouls",
    "saturation_o2",
    "tension_systolique",
    "tension_diastolique",
    "poids",
    "duree_jours",
    "intensite",
    "grossesse",
    "score_antecedents",
]

# ``bande_age`` est une chaîne (« 15-24 ») — pas d'ordre implicite pour XGBoost sans conversion.
# Table FERMÉE, partagée par Laravel (config `masante.triage_ia.bandes_age`) et ce module : une
# bande absente d'ici lève, elle ne vaut jamais silencieusement 0 (précédent F15/refus bruyant).
_ORDRE_BANDES: dict[str, int] = {
    "0-1": 0, "2-4": 1, "5-14": 2, "15-24": 3, "25-44": 4, "45-64": 5, "65+": 6,
}


class BandeAgeInconnueError(ValueError):
    """Une tranche que ce service ne connaît pas — donc une divergence avec la config Laravel.

    Sous-classe de ``ValueError`` : c'en est littéralement une, et tout appelant qui attrapait déjà
    ``ValueError`` continue de fonctionner. Le type nommé ne sert qu'à la rendre **présentable** —
    voir ci-dessous.

    ═══ POURQUOI UNE EXCEPTION NOMMÉE, ET PAS UN ``ValueError`` NU ═══

    Un ``ValueError`` remonte en **500 opaque** à travers FastAPI : l'exploitant verrait « erreur
    interne » là où le fait est précis et actionnable — les bornes ont divergé entre la config
    Laravel (F26) et la table d'ordre ci-dessus. La leçon est celle de P10c-3-i, où une classe à un
    seul exemplaire rendait un 500 pour un motif parfaitement nommable.

    Le refus **nomme** la tranche reçue et celles qui sont admises : refuser sans dire quoi envoyer
    laisserait chercher (motif P6.8a).
    """


def vecteur_features(ligne: TraitsCliniques) -> dict[str, float]:
    """Traduit des traits cliniques en vecteur numérique — la SEULE fonction qui le fait.

    ═══ P10c-3-ii (F25) — LA SIGNATURE EST LA GARANTIE ═══

    Elle prend un ``TraitsCliniques``, dont héritent la ligne d'entraînement ET la requête de score.
    Trois conséquences qui ne dépendent plus de la vigilance de personne :

    1. les deux côtés voient exactement les mêmes champs (le décalage Z8 devient inexprimable) ;
    2. ``niveau_protocole`` et ``reference``, déclarés sur la sous-classe de score, sont
       **inaccessibles ici** — D3 de P10c-2-i cesse d'être une promesse de commentaire ;
    3. ``label``, ``maladie_code``, ``niveau_reel`` et ``specialite_code``, déclarés sur la
       sous-classe d'entraînement, le sont tout autant : on ne peut pas prédire le diagnostic à
       partir du diagnostic (F36).
    """
    if ligne.bande_age is not None and ligne.bande_age not in _ORDRE_BANDES:
        raise BandeAgeInconnueError(
            f"Bande d'âge inconnue « {ligne.bande_age} ». Bandes admises : "
            f"{', '.join(_ORDRE_BANDES)}."
        )

    c = ligne.constantes

    return {
        "age_bande_ordinale": float(_ORDRE_BANDES[ligne.bande_age]) if ligne.bande_age else float("nan"),
        "sexe_f": 1.0 if ligne.sexe == "F" else (0.0 if ligne.sexe == "M" else float("nan")),
        "nb_symptomes": float(len(ligne.symptomes)),
        "temperature": c.temperature if c.temperature is not None else float("nan"),
        "pouls": c.pouls if c.pouls is not None else float("nan"),
        "saturation_o2": c.saturation_o2 if c.saturation_o2 is not None else float("nan"),
        "tension_systolique": c.tension_systolique if c.tension_systolique is not None else float("nan"),
        "tension_diastolique": c.tension_diastolique if c.tension_diastolique is not None else float("nan"),
        "poids": c.poids if c.poids is not None else float("nan"),
        "duree_jours": float(ligne.duree_jours) if ligne.duree_jours is not None else float("nan"),
        "intensite": float(ligne.intensite) if ligne.intensite is not None else float("nan"),
        "grossesse": 1.0 if ligne.grossesse else (0.0 if ligne.grossesse is False else float("nan")),
        "score_antecedents": (
            float(ligne.score_antecedents) if ligne.score_antecedents is not None else float("nan")
        ),
    }
