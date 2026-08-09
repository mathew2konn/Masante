"""G3 — extraction réelle (incrément A). Tests PURS via httpx.MockTransport : aucun réseau, aucune base.

On prouve : (1) la signature de principal est un HMAC valide lié à method+path ; (2) l'adaptateur porte
bien les en-têtes signés et normalise camelCase→snake_case ; (3) la dégradation est HONNÊTE (404→
PieceIntrouvable, 5xx/erreur réseau→SourceIndisponible, jamais un score inventé).
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
from datetime import UTC, datetime

import httpx
import pytest

from app.extraction.principal import SignatairePrincipal
from app.extraction.source import (
    AdaptateurSignauxPaiement,
    PieceIntrouvable,
    SourceIndisponible,
    vers_signal,
)

SECRET_B64 = base64.b64encode(b"secret-test-0123456789").decode("ascii")

CHARGE = {
    "reference": "FCT-TEST",
    "etablissementRef": "ETB-1",
    "montantTtc": 50000,
    "montantCouvert": 35000,
    "resteAPayer": 15000,
    "montantActe": 45000,
    "montantActeReference": 35000,
    "nbFacturesEtablissement30j": 3,
    "nbActesIdentiquesJour": 1,
    "nbRemboursementsCarte7j": 2,
    "montantCumuleWallet24h": 50000,
    "nbOpsWallet1h": 2,
    "heureOperation": 10,
    "delaiFacturePaiementMinutes": 25,
}


def signataire() -> SignatairePrincipal:
    return SignatairePrincipal(SECRET_B64, "fraud-detection-service", "ADMIN_FINANCE")


def adaptateur(handler) -> AdaptateurSignauxPaiement:
    return AdaptateurSignauxPaiement(
        "http://payment:8080", signataire(), transport=httpx.MockTransport(handler))


# ── Signature du principal ──────────────────────────────────────────────────────────────────────

def test_principal_hmac_valide_et_lie_a_la_requete():
    entetes = signataire().entetes("GET", "/api/v1/fraud-signals/FCT-TEST")
    principal = entetes["X-Principal"]
    claims = json.loads(base64.b64decode(principal))

    assert claims["method"] == "GET"
    assert claims["path"] == "/api/v1/fraud-signals/FCT-TEST"
    assert claims["roles"] == ["ADMIN_FINANCE"]
    assert claims["exp"] > claims["iat"]

    attendu = hmac.new(base64.b64decode(SECRET_B64), principal.encode("utf-8"), hashlib.sha256).digest()
    assert base64.b64decode(entetes["X-Principal-Sig"]) == attendu


def test_principal_nonce_unique_par_appel():
    s = signataire()
    n1 = json.loads(base64.b64decode(s.entetes("GET", "/x")["X-Principal"]))["nonce"]
    n2 = json.loads(base64.b64decode(s.entetes("GET", "/x")["X-Principal"]))["nonce"]
    assert n1 != n2


def test_principal_secret_absent_refuse():
    with pytest.raises(ValueError):
        SignatairePrincipal("", "sub", "ADMIN_FINANCE")


# ── Normalisation ───────────────────────────────────────────────────────────────────────────────

def test_mapping_camel_vers_snake():
    signal = vers_signal(CHARGE)
    assert signal.reference == "FCT-TEST"
    assert signal.etablissement_ref == "ETB-1"
    assert signal.montant_ttc == 50000
    assert signal.reste_a_payer == 15000
    assert signal.montant_acte_reference == 35000
    assert signal.nb_ops_wallet_1h == 2
    assert signal.delai_facture_paiement_minutes == 25


# ── Adaptateur HTTP ─────────────────────────────────────────────────────────────────────────────

def test_signaux_succes_porte_entetes_signes_et_normalise():
    captures: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        captures.append(request)
        return httpx.Response(200, json=CHARGE)

    signal = adaptateur(handler).signaux("FCT-TEST")

    assert signal.montant_ttc == 50000
    req = captures[0]
    assert req.url.path == "/api/v1/fraud-signals/FCT-TEST"
    assert "X-Principal" in req.headers and "X-Principal-Sig" in req.headers
    # Le chemin signé == le chemin réellement appelé (liaison à la requête).
    claims = json.loads(base64.b64decode(req.headers["X-Principal"]))
    assert claims["path"] == req.url.path
    assert claims["method"] == "GET"


def test_signaux_transmet_as_of_en_query():
    captures: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        captures.append(request)
        return httpx.Response(200, json=CHARGE)

    t = datetime(2026, 8, 9, 12, 0, 0, tzinfo=UTC)
    adaptateur(handler).signaux("FCT-TEST", as_of=t)
    assert captures[0].url.params["asOf"] == "2026-08-09T12:00:00Z"


def test_signaux_404_leve_piece_introuvable():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(404, json={"detail": "Facture introuvable"})

    with pytest.raises(PieceIntrouvable):
        adaptateur(handler).signaux("FCT-ZZZ")


def test_signaux_5xx_leve_source_indisponible():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(503, text="down")

    with pytest.raises(SourceIndisponible):
        adaptateur(handler).signaux("FCT-TEST")


def test_signaux_erreur_reseau_leve_source_indisponible():
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("connexion refusée")

    with pytest.raises(SourceIndisponible):
        adaptateur(handler).signaux("FCT-TEST")


def test_signaux_corps_illisible_leve_source_indisponible():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"reference": "FCT-TEST"})  # champs manquants

    with pytest.raises(SourceIndisponible):
        adaptateur(handler).signaux("FCT-TEST")


def test_signaux_lot_succes_liste():
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/api/v1/fraud-signals/lot"
        corps = json.loads(request.content)
        return httpx.Response(200, json=[CHARGE for _ in corps["references"]])

    signaux = adaptateur(handler).signaux_lot(["FCT-TEST", "FCT-A"])
    assert len(signaux) == 2
    assert all(s.montant_ttc == 50000 for s in signaux)
