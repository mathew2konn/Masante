"""Générateur de données SYNTHÉTIQUES de démonstration.

⚠️ HONNÊTETÉ (décision G1) : ces données sont FABRIQUÉES, pas réelles. CDC_05 §7.2 interdit
d'entraîner sur les données de production ; aucun jeu anonymisé + validé médecin n'existe encore.
Ce générateur sert UNIQUEMENT à démontrer la mécanique ML/SHAP de bout en bout — le modèle produit
n'est JAMAIS « validé cliniquement ».

Conception pour une explicabilité SHAP PARLANTE : les features sont tirées d'une population UNIQUE
(fort recouvrement — aucune feature ne sépare seule les classes), et le label est construit depuis une
COMBINAISON pondérée de plusieurs features + bruit. Le modèle doit donc combiner plusieurs signaux →
SHAP répartit ses contributions sur plusieurs features, et l'AUC reste réaliste (pas ~1.0 trompeur).
Graine fixée => reproductibilité (§8). Colonnes produites dans l'ordre de ``NOMS_FEATURES``.
"""
from __future__ import annotations

import numpy as np
import pandas as pd

from ..domain.features import NOMS_FEATURES


def _z(x: np.ndarray) -> np.ndarray:
    return (x - x.mean()) / (x.std() + 1e-9)


def generer(n: int = 6000, graine: int = 42) -> tuple[pd.DataFrame, pd.Series]:
    rng = np.random.default_rng(graine)

    # --- Population UNIQUE (recouvrement fort ; aucune feature ne sépare seule) ---
    montant_ttc = rng.lognormal(mean=10.6, sigma=0.7, size=n)
    ratio_couverture = np.clip(rng.beta(2.0, 2.0, n) * 1.15, 0.0, 1.5)      # parfois > 1 (anormal)
    couverture_excessive = (ratio_couverture > 1.0).astype(float)
    desequilibre = rng.choice([0, 0, 0, 0, 5000, -3000, 12000], size=n).astype(float)
    ratio_acte = np.clip(rng.gamma(2.0, 0.85, n), 0.3, 12.0)                # centré ~1.7, queue haute
    nb_factures = rng.poisson(30, n).astype(float)
    nb_actes = rng.poisson(10, n).astype(float)
    nb_remb = rng.poisson(2, n).astype(float)
    cumul_wallet = rng.lognormal(mean=12.2, sigma=1.0, size=n)
    nb_ops_wallet = rng.poisson(8, n).astype(float)
    heure = rng.integers(0, 24, n).astype(float)
    delai = np.clip(rng.normal(25, 15, n), 0.0, None)

    heure_nuit = ((heure >= 0) & (heure <= 5)).astype(float)

    # --- Label depuis une combinaison de PLUSIEURS features + bruit ---
    risque = (
        0.9 * _z(ratio_acte)
        + 0.8 * _z(nb_factures)
        + 0.8 * _z(nb_actes)
        + 0.6 * _z(nb_remb)
        + 0.7 * _z(cumul_wallet)
        + 0.7 * _z(nb_ops_wallet)
        + 0.5 * _z(montant_ttc)
        - 0.5 * _z(delai)
        + 1.0 * couverture_excessive
        + 0.8 * (desequilibre != 0).astype(float)
        + 0.7 * heure_nuit
    )
    risque = risque + rng.normal(0.0, 1.5, n)          # bruit => pas parfaitement séparable
    y = (risque > np.median(risque)).astype(int)       # ~50/50

    colonnes = {
        "montant_ttc": montant_ttc,
        "ratio_couverture": ratio_couverture,
        "desequilibre_comptable": desequilibre,
        "couverture_excessive": couverture_excessive,
        "ratio_acte_reference": ratio_acte,
        "nb_factures_etablissement_30j": nb_factures,
        "nb_actes_identiques_jour": nb_actes,
        "nb_remboursements_carte_7j": nb_remb,
        "montant_cumule_wallet_24h": cumul_wallet,
        "nb_ops_wallet_1h": nb_ops_wallet,
        "heure_operation": heure,
        "delai_facture_paiement_minutes": delai,
    }
    df = pd.DataFrame(colonnes, columns=NOMS_FEATURES)
    return df, pd.Series(y, name="fraude")
