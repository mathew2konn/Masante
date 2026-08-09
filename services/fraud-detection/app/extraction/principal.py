"""Signature du PRINCIPAL (P5.5b-1), reproduite en stdlib — aucune dépendance nouvelle.

Construit les en-têtes ``X-Principal`` = base64(JSON ``{sub,roles,iat,exp,method,path,nonce}``) et
``X-Principal-Sig`` = base64(HMAC-SHA256(octets bruts de X-Principal, secret partagé)). Le service
paiement (``ServicePrincipal.verifier``) contrôle : signature (temps constant), fraîcheur (iat/exp
±5 min), liaison à la requête (method + path), anti-rejeu (nonce à usage unique en Redis), et le rôle.

Le ``path`` signé doit être EXACTEMENT le ``requestURI`` vu par le paiement (chemin sans query-string) —
d'où la signature du chemin déjà encodé côté adaptateur.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import time
import uuid


class SignatairePrincipal:
    """Fabrique d'en-têtes de principal signé. Le secret (base64) vient de l'environnement, jamais du code."""

    _FRAICHEUR_S = 120  # durée de vie du jeton, bien en deçà des 5 min tolérées côté paiement

    def __init__(self, secret_b64: str, sub: str, role: str) -> None:
        if not secret_b64:
            raise ValueError("Secret de principal absent : signature impossible.")
        self._secret = base64.b64decode(secret_b64)
        self._sub = sub
        self._role = role

    def entetes(self, methode: str, chemin: str) -> dict[str, str]:
        """En-têtes signés pour un appel {méthode, chemin} donné (nonce neuf à chaque appel)."""
        maintenant = int(time.time())
        claims = {
            "sub": self._sub,
            "roles": [self._role],
            "iat": maintenant,
            "exp": maintenant + self._FRAICHEUR_S,
            "method": methode,
            "path": chemin,
            "nonce": uuid.uuid4().hex,
        }
        principal = base64.b64encode(json.dumps(claims).encode("utf-8")).decode("ascii")
        signature = hmac.new(self._secret, principal.encode("utf-8"), hashlib.sha256).digest()
        return {
            "X-Principal": principal,
            "X-Principal-Sig": base64.b64encode(signature).decode("ascii"),
        }
