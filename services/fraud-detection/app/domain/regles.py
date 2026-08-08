"""Moteur de règles déterministe (couche « Phase 1 » CDC_05 §7.3/§12 — fonctionne SANS ML).

Fonction pure, sans I/O, testable en unitaire. Chaque règle est explicable (valeur observée + seuil)
et son seuil est une DONNÉE (config), jamais du métier codé en dur. Additionne des poids par règle
déclenchée. Les incohérences DURES (certitudes déterministes) posent ``force_escalade`` : les règles
font autorité, l'IA ne peut pas les « minorer » (frontière CDC_05 §1.6).
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from ..config import Parametres
    from ..schemas import SignalFacturation


@dataclass(frozen=True)
class ReglePure:
    code: str
    libelle: str
    poids: int
    valeur_observee: float
    seuil: float


@dataclass
class ResultatRegles:
    score: int
    regles: list[ReglePure] = field(default_factory=list)
    force_escalade: bool = False


def evaluer_regles(signal: SignalFacturation, p: Parametres) -> ResultatRegles:
    regles: list[ReglePure] = []
    force = False

    # 1. Incohérence de prise en charge : part couverte > TTC (impossible légitimement) — certitude
    if signal.montant_couvert > signal.montant_ttc:
        regles.append(ReglePure(
            "INCOHERENCE_PRISE_EN_CHARGE",
            "Part prise en charge supérieure au montant TTC",
            p.poids_incoherence_couverture,
            float(signal.montant_couvert), float(signal.montant_ttc)))
        force = True

    # 2. Déséquilibre comptable : couvert + reste à payer ≠ TTC (invariant paiement rompu) — certitude
    ecart = (signal.montant_couvert + signal.reste_a_payer) - signal.montant_ttc
    if ecart != 0:
        regles.append(ReglePure(
            "DESEQUILIBRE_COMPTABLE",
            "Couvert + reste à payer différent du TTC",
            p.poids_desequilibre_comptable,
            float(ecart), 0.0))
        force = True

    # 3. Montant d'acte aberrant vs référentiel
    seuil_acte = p.seuil_multiple_acte * signal.montant_acte_reference
    if signal.montant_acte_reference > 0 and signal.montant_acte > seuil_acte:
        regles.append(ReglePure(
            "MONTANT_ACTE_ABERRANT",
            "Montant de l'acte très supérieur à la référence",
            p.poids_montant_aberrant,
            float(signal.montant_acte), float(seuil_acte)))

    # 4. Vélocité de facturation établissement
    if signal.nb_factures_etablissement_30j >= p.seuil_factures_30j:
        regles.append(ReglePure(
            "VELOCITE_FACTURATION",
            "Nombre de factures anormalement élevé sur 30 j",
            p.poids_velocite_factures,
            float(signal.nb_factures_etablissement_30j), float(p.seuil_factures_30j)))

    # 5. Actes identiques répétés dans la journée
    if signal.nb_actes_identiques_jour >= p.seuil_actes_jour:
        regles.append(ReglePure(
            "ACTES_REPETES",
            "Actes identiques répétés en une journée",
            p.poids_actes_repetes,
            float(signal.nb_actes_identiques_jour), float(p.seuil_actes_jour)))

    # 6. Remboursements carte en rafale
    if signal.nb_remboursements_carte_7j >= p.seuil_remboursements_7j:
        regles.append(ReglePure(
            "REMBOURSEMENTS_RAFALE",
            "Remboursements carte en rafale sur 7 j",
            p.poids_remboursements_rafale,
            float(signal.nb_remboursements_carte_7j), float(p.seuil_remboursements_7j)))

    # 7. Cumul wallet anormal (montant)
    if signal.montant_cumule_wallet_24h >= p.seuil_cumul_wallet_24h:
        regles.append(ReglePure(
            "CUMUL_WALLET_ANORMAL",
            "Cumul des opérations wallet anormal sur 24 h",
            p.poids_cumul_wallet,
            float(signal.montant_cumule_wallet_24h), float(p.seuil_cumul_wallet_24h)))

    # 8. Vélocité wallet (nombre d'opérations)
    if signal.nb_ops_wallet_1h >= p.seuil_ops_wallet_1h:
        regles.append(ReglePure(
            "VELOCITE_WALLET",
            "Trop d'opérations wallet en 1 h",
            p.poids_velocite_wallet,
            float(signal.nb_ops_wallet_1h), float(p.seuil_ops_wallet_1h)))

    # 9. Horaire inhabituel
    if p.heure_inhabituelle_debut <= signal.heure_operation <= p.heure_inhabituelle_fin:
        regles.append(ReglePure(
            "HORAIRE_INHABITUEL",
            "Opération à une heure inhabituelle",
            p.poids_horaire,
            float(signal.heure_operation), float(p.heure_inhabituelle_fin)))

    score = sum(r.poids for r in regles)
    return ResultatRegles(score=score, regles=regles, force_escalade=force)
