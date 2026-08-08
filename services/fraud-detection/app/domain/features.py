"""Feature engineering — SOURCE UNIQUE de l'ordre des features.

Le même vecteur est produit ici (au service) et par le générateur synthétique (à l'entraînement),
ce qui évite tout décalage train/serve. Fonction pure, sans I/O.
"""
from __future__ import annotations

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from ..schemas import SignalFacturation

# Ordre canonique des features consommées par XGBoost. Ne pas réordonner sans réentraîner.
NOMS_FEATURES: list[str] = [
    "montant_ttc",
    "ratio_couverture",
    "desequilibre_comptable",
    "couverture_excessive",
    "ratio_acte_reference",
    "nb_factures_etablissement_30j",
    "nb_actes_identiques_jour",
    "nb_remboursements_carte_7j",
    "montant_cumule_wallet_24h",
    "nb_ops_wallet_1h",
    "heure_operation",
    "delai_facture_paiement_minutes",
]


def vecteur_features(signal: SignalFacturation) -> dict[str, float]:
    """Transforme un signalement brut en vecteur de features ordonné (dict clé→valeur)."""
    ttc = signal.montant_ttc
    ratio_couverture = signal.montant_couvert / ttc if ttc > 0 else 0.0
    desequilibre = float((signal.montant_couvert + signal.reste_a_payer) - ttc)
    couverture_excessive = 1.0 if signal.montant_couvert > ttc else 0.0
    ref = signal.montant_acte_reference
    ratio_acte = signal.montant_acte / ref if ref > 0 else 1.0

    return {
        "montant_ttc": float(ttc),
        "ratio_couverture": float(ratio_couverture),
        "desequilibre_comptable": desequilibre,
        "couverture_excessive": couverture_excessive,
        "ratio_acte_reference": float(ratio_acte),
        "nb_factures_etablissement_30j": float(signal.nb_factures_etablissement_30j),
        "nb_actes_identiques_jour": float(signal.nb_actes_identiques_jour),
        "nb_remboursements_carte_7j": float(signal.nb_remboursements_carte_7j),
        "montant_cumule_wallet_24h": float(signal.montant_cumule_wallet_24h),
        "nb_ops_wallet_1h": float(signal.nb_ops_wallet_1h),
        "heure_operation": float(signal.heure_operation),
        "delai_facture_paiement_minutes": float(signal.delai_facture_paiement_minutes),
    }
