<?php

namespace App\Services\Triage;

/**
 * P10c-2-i (F7) — Le résultat d'un appel à `triage-service`.
 *
 * SEULE {@see degrade()} EXISTE AUJOURD'HUI : sans modèle (F5), aucun appel ne peut réussir en mode
 * `hybride` — pas même en théorie, {@see ClientTriageIa} ne construit jamais
 * cette branche. L'ajouter maintenant, vide de la probabilité/des facteurs/de l'explication que
 * P10c-3 lui donnera un sens, serait le socle à vide refusé par P6.3-D3.
 */
final class ResultatTriageIa
{
    private function __construct(
        public readonly string $mode,
        public readonly ?string $motifDegradation,
        public readonly int $latenceMs,
    ) {}

    public static function degrade(string $motif, int $latenceMs): self
    {
        return new self('degrade', $motif, $latenceMs);
    }
}
