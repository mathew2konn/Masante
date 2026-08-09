"""Source de signaux : port (Protocol) + adaptateur HTTP vers le service paiement.

Honnêteté / dégradation gracieuse : si la source est injoignable ou répond mal, on lève une erreur
CLAIRE (→ 502) — on n'invente JAMAIS un score sur des données absentes. C'est le pendant, pour la
donnée, de « modèle absent → règles seules » : on ne fabrique pas ce qu'on ne peut pas lire.
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Any, Protocol
from urllib.parse import quote

import httpx

from ..schemas import SignalFacturation


class SourceIndisponible(RuntimeError):
    """La source des signaux est injoignable, en erreur, ou renvoie un corps illisible → 502 honnête."""


class PieceIntrouvable(RuntimeError):
    """La référence demandée n'existe pas côté paiement (404 amont) → 404 propagé."""

    def __init__(self, reference: str) -> None:
        super().__init__(f"Référence introuvable côté paiement : {reference}")
        self.reference = reference


def _iso_utc(instant: datetime) -> str:
    """ISO-8601 UTC avec 'Z' (format accepté par le paiement, cf. G2). Naïf => interprété UTC."""
    if instant.tzinfo is None:
        instant = instant.replace(tzinfo=UTC)
    return instant.astimezone(UTC).isoformat().replace("+00:00", "Z")


def vers_signal(charge: dict[str, Any]) -> SignalFacturation:
    """Normalise la réponse du paiement (camelCase) vers le contrat interne (snake_case).

    Frontière anti-corruption explicite et testable : c'est ICI, et nulle part ailleurs, que la forme
    externe est traduite. Toute divergence de schéma se voit d'un coup d'œil dans cette fonction.
    """
    return SignalFacturation(
        reference=charge["reference"],
        etablissement_ref=charge["etablissementRef"],
        montant_ttc=charge["montantTtc"],
        montant_couvert=charge["montantCouvert"],
        reste_a_payer=charge["resteAPayer"],
        montant_acte=charge["montantActe"],
        montant_acte_reference=charge["montantActeReference"],
        nb_factures_etablissement_30j=charge["nbFacturesEtablissement30j"],
        nb_actes_identiques_jour=charge["nbActesIdentiquesJour"],
        nb_remboursements_carte_7j=charge["nbRemboursementsCarte7j"],
        montant_cumule_wallet_24h=charge["montantCumuleWallet24h"],
        nb_ops_wallet_1h=charge["nbOpsWallet1h"],
        heure_operation=charge["heureOperation"],
        delai_facture_paiement_minutes=charge["delaiFacturePaiementMinutes"],
    )


class SourceSignaux(Protocol):
    """Port : fournit les signaux d'une facture (unitaire ou lot) à partir de sa référence."""

    def signaux(self, reference: str, as_of: datetime | None = None) -> SignalFacturation: ...

    def signaux_lot(
        self, references: list[str], as_of: datetime | None = None
    ) -> list[SignalFacturation]: ...


class AdaptateurSignauxPaiement:
    """Adaptateur HTTP vers l'endpoint read-only /fraud-signals du paiement, gardé par principal signé."""

    def __init__(
        self,
        base_url: str,
        signataire: Any,
        timeout_s: float = 5.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._signataire = signataire
        self._client = httpx.Client(base_url=self._base_url, timeout=timeout_s, transport=transport)

    def signaux(self, reference: str, as_of: datetime | None = None) -> SignalFacturation:
        chemin = "/api/v1/fraud-signals/" + quote(reference, safe="")
        params = {"asOf": _iso_utc(as_of)} if as_of is not None else None
        entetes = self._signataire.entetes("GET", chemin)
        try:
            reponse = self._client.get(chemin, params=params, headers=entetes)
        except httpx.HTTPError as e:
            raise SourceIndisponible(f"Paiement injoignable : {e}") from e
        if reponse.status_code == 404:
            raise PieceIntrouvable(reference)
        if reponse.status_code != 200:
            raise SourceIndisponible(f"Réponse paiement inattendue : HTTP {reponse.status_code}")
        return self._lire_un(reponse)

    def signaux_lot(
        self, references: list[str], as_of: datetime | None = None
    ) -> list[SignalFacturation]:
        chemin = "/api/v1/fraud-signals/lot"
        corps: dict[str, Any] = {"references": references}
        if as_of is not None:
            corps["asOf"] = _iso_utc(as_of)
        entetes = self._signataire.entetes("POST", chemin)
        try:
            reponse = self._client.post(chemin, json=corps, headers=entetes)
        except httpx.HTTPError as e:
            raise SourceIndisponible(f"Paiement injoignable : {e}") from e
        if reponse.status_code == 404:
            # Au moins une référence du lot est inconnue côté paiement (réponse atomique).
            raise PieceIntrouvable(", ".join(references))
        if reponse.status_code != 200:
            raise SourceIndisponible(f"Réponse paiement inattendue : HTTP {reponse.status_code}")
        try:
            charges = reponse.json()
            return [vers_signal(c) for c in charges]
        except (ValueError, KeyError, TypeError) as e:
            raise SourceIndisponible(f"Corps de réponse paiement illisible : {e}") from e

    def _lire_un(self, reponse: httpx.Response) -> SignalFacturation:
        try:
            return vers_signal(reponse.json())
        except (ValueError, KeyError, TypeError) as e:
            raise SourceIndisponible(f"Corps de réponse paiement illisible : {e}") from e
