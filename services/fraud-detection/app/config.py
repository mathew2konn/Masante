"""Configuration du service — seuils et poids sont des DONNÉES (jamais du métier codé en dur).

Frontière CDC_05 : toute la logique de détection vit dans le backend ; ici on paramètre.
Surchargeable par variables d'environnement préfixées ``FRAUD_`` (ex. ``FRAUD_SEUIL_SUSPECT=40``).
"""
from __future__ import annotations

from pydantic_settings import BaseSettings, SettingsConfigDict


class Parametres(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="FRAUD_", env_file=".env", extra="ignore")

    # --- Modèle / MLflow ---
    # Artefact absent => dégradation gracieuse : le service répond en règles seules (§1.7 / §10).
    modele_path: str = "models/modele_fraude.json"
    mlflow_tracking_uri: str = "file:./mlruns"

    # --- Extraction réelle (incrément A) : source des signaux = service paiement ---
    # Le service paiement expose les signaux (projection read-only de SON schéma) ; on ne lit JAMAIS
    # sa base directement (ADR-014/ADR-019). Endpoint sensible → principal signé (P5.5b-1) + ADMIN_FINANCE.
    # Secret HMAC (base64) partagé, fourni par l'ENVIRONNEMENT (jamais en dur, CDC_00 §4). Vide =>
    # extraction désactivée : /score-ref & /scan-refs répondent 502 honnête (le POST-signaux reste OK).
    payment_base_url: str = "http://payment:8080"
    principal_secret: str = ""
    principal_sub: str = "fraud-detection-service"
    principal_role: str = "ADMIN_FINANCE"
    http_timeout_s: float = 5.0

    # --- Seuils de règles (DONNÉES) ---
    seuil_multiple_acte: float = 3.0          # montant d'acte > 3× la référence = aberrant
    seuil_factures_30j: int = 50              # vélocité de facturation établissement
    seuil_actes_jour: int = 20                # actes identiques répétés dans la journée
    seuil_remboursements_7j: int = 5          # remboursements carte en rafale
    seuil_cumul_wallet_24h: int = 2_000_000   # XOF cumulés sur 24 h
    seuil_ops_wallet_1h: int = 15             # vélocité d'opérations wallet
    heure_inhabituelle_debut: int = 0
    heure_inhabituelle_fin: int = 5

    # --- Poids des règles (contribution au score) ---
    poids_incoherence_couverture: int = 60
    poids_desequilibre_comptable: int = 40
    poids_montant_aberrant: int = 35
    poids_velocite_factures: int = 25
    poids_actes_repetes: int = 25
    poids_remboursements_rafale: int = 30
    poids_cumul_wallet: int = 30
    poids_velocite_wallet: int = 25
    poids_horaire: int = 10

    # --- Fusion hybride (règles font autorité, ML indicatif) ---
    poids_regles: float = 0.6
    poids_ml: float = 0.4
    seuil_suspect: int = 35
    seuil_tres_suspect: int = 70


parametres = Parametres()
