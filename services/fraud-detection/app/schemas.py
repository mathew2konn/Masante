"""Contrat d'API (Pydantic → OpenAPI). Le vecteur d'entrée = les SIGNAUX PAIEMENT INTERNES
(factures, prise en charge, wallet, remboursements) déjà présents dans le domaine paiement.

Note importante : on n'interdit PAS ``montant_couvert > montant_ttc`` au niveau du schéma —
c'est justement une incohérence de fraude que le moteur doit pouvoir recevoir et signaler.
"""
from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field


class SignalFacturation(BaseModel):
    """Un signalement à évaluer (dérivé d'une facture / opération paiement)."""

    reference: str = Field(..., description="Identifiant du signalement (réf. facture ou opération)")
    etablissement_ref: str = Field(..., description="Établissement concerné")
    montant_ttc: int = Field(..., ge=0, description="Montant TTC de la facture (XOF, entier)")
    montant_couvert: int = Field(0, ge=0, description="Part prise en charge (assurance/CNAM)")
    reste_a_payer: int = Field(0, ge=0, description="Reste à charge patient")
    montant_acte: int = Field(0, ge=0, description="Montant de l'acte facturé")
    montant_acte_reference: int = Field(0, ge=0, description="Montant moyen de référence pour cet acte")
    nb_factures_etablissement_30j: int = Field(0, ge=0, description="Nb factures établissement / 30 j")
    nb_actes_identiques_jour: int = Field(0, ge=0, description="Nb d'actes identiques / jour")
    nb_remboursements_carte_7j: int = Field(0, ge=0, description="Nb de remboursements carte sur 7 j")
    montant_cumule_wallet_24h: int = Field(0, ge=0, description="Cumul des opérations wallet sur 24 h (XOF)")
    nb_ops_wallet_1h: int = Field(0, ge=0, description="Nb d'opérations wallet sur 1 h")
    heure_operation: int = Field(12, ge=0, le=23, description="Heure de l'opération (0-23)")
    delai_facture_paiement_minutes: int = Field(0, ge=0, description="Délai facture → paiement (minutes)")

    model_config = {
        "json_schema_extra": {
            "example": {
                "reference": "FACT-2026-000123",
                "etablissement_ref": "ETB-ABJ-001",
                "montant_ttc": 30000,
                "montant_couvert": 21000,
                "reste_a_payer": 9000,
                "montant_acte": 30000,
                "montant_acte_reference": 28000,
                "nb_factures_etablissement_30j": 12,
                "nb_actes_identiques_jour": 3,
                "nb_remboursements_carte_7j": 0,
                "montant_cumule_wallet_24h": 45000,
                "nb_ops_wallet_1h": 2,
                "heure_operation": 10,
                "delai_facture_paiement_minutes": 20,
            }
        }
    }


class RegleDeclenchee(BaseModel):
    code: str
    libelle: str
    poids: int
    valeur_observee: float
    seuil: float


class FacteurMl(BaseModel):
    feature: str
    contribution: float = Field(..., description="Valeur SHAP (>0 augmente le risque, <0 le diminue)")
    valeur: float = Field(..., description="Valeur de la feature pour ce signalement")


class ResultatFraude(BaseModel):
    reference: str
    niveau: str = Field(..., description="NORMAL | SUSPECT | TRES_SUSPECT")
    score: int = Field(..., ge=0, le=100)
    probabilite_ml: float | None = Field(None, description="Probabilité XGBoost (null si ML indisponible)")
    mode: str = Field(..., description="hybride | regles_seules (dégradation gracieuse)")
    regles_declenchees: list[RegleDeclenchee]
    facteurs_ml: list[FacteurMl]
    explication: list[str] = Field(..., description="Explication non vide (Rule-005)")
    confiance: str = Field(..., description="elevee | moyenne | reduite")
    limites: str
    action: str = Field(..., description="Toujours détection seule : aucune action automatique")


class RequeteScan(BaseModel):
    signaux: list[SignalFacturation]


class RequeteScoreRef(BaseModel):
    """Score par RÉFÉRENCE : les signaux sont extraits en temps réel du service paiement (incrément A)."""

    reference: str = Field(..., description="Référence de facture à extraire puis scorer")
    as_of: datetime | None = Field(
        None, description="Cut-off T optionnel (ISO-8601) pour la reproductibilité ; défaut = maintenant")


class RequeteScanRefs(BaseModel):
    """Scan par LOT de références : extraction au même cut-off puis scoring (§6.9)."""

    references: list[str] = Field(..., min_length=1, description="Références de factures à extraire+scorer")
    as_of: datetime | None = Field(None, description="Cut-off T optionnel commun au lot")


class ResumeScan(BaseModel):
    total: int
    normal: int
    suspect: int
    tres_suspect: int
    mode: str


class ReponseScan(BaseModel):
    resume: ResumeScan
    resultats: list[ResultatFraude]
