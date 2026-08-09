"""Extraction réelle des signaux depuis le service paiement (incrément A, lève la dette d'extraction).

Frontière anti-corruption (ADR-014/ADR-019) : la fraude ne lit JAMAIS la base paiement ; elle consomme
l'endpoint read-only /fraud-signals (projection possédée par le paiement) et normalise sa réponse
(camelCase) vers le contrat interne ``SignalFacturation`` (snake_case). Canal authentifié par principal
signé (P5.5b-1) reproduit en stdlib. DÉTECTION SEULE inchangée : on lit, on score, on n'agit pas.
"""
