<?php

namespace App\Services\Triage;

/**
 * P10c-2-i (F7), complété par P10c-3-ii (F22) — Le résultat d'un appel à `triage-service`.
 *
 * ═══ LA SECONDE BRANCHE S'APPELLE `observation`, ET SURTOUT PAS `hybride` ═══
 *
 * P10c-2-i avait réservé `hybride` pour le jour où l'IA participerait à la décision. Ce jour n'est
 * pas celui-ci, et le mot compte : CDC_08 §3 place le raisonnement IA au **sixième et dernier**
 * rang, « jamais pour contredire un protocole officiel ». Le modèle a répondu, sa réponse est
 * enregistrée, elle n'a rien décidé — l'appeler `hybride` affirmerait une participation qui n'a pas
 * lieu, et un mot faux dans un journal médico-légal est un défaut, pas une approximation.
 *
 * `hybride` reste donc inatteignable, dans l'ENUM et dans cette classe.
 *
 * ═══ CE QUE PORTE UNE OBSERVATION, ET POURQUOI TOUT EST OBLIGATOIRE ═══
 *
 * Rule-005 : « aucune IA ne prend de décision médicale sans expliquer son raisonnement […] le score
 * de confiance et les limites ». Les champs ne sont donc pas nullables « au cas où » : une
 * prédiction sans explication n'est pas une prédiction dégradée, c'est une violation — et le
 * constructeur ne permet pas de la fabriquer.
 */
final class ResultatTriageIa
{
    /**
     * @param  array<int, array<string, mixed>>|null  $facteurs
     * @param  array<string, mixed>|null  $explication
     */
    private function __construct(
        public readonly string $mode,
        public readonly ?string $motifDegradation,
        public readonly int $latenceMs,
        public readonly ?string $modeleVersion = null,
        public readonly ?float $probabilite = null,
        public readonly ?array $facteurs = null,
        public readonly ?array $explication = null,
        public readonly ?string $confiance = null,
        public readonly ?string $limites = null,
    ) {}

    public static function degrade(string $motif, int $latenceMs): self
    {
        return new self('degrade', $motif, $latenceMs);
    }

    /**
     * Une prédiction réelle, enregistrée et sans effet sur la décision (F22).
     *
     * @param  array<int, array<string, mixed>>  $facteurs
     * @param  array<string, mixed>  $explication
     */
    public static function observation(
        string $modeleVersion,
        float $probabilite,
        array $facteurs,
        array $explication,
        string $confiance,
        string $limites,
        int $latenceMs,
    ): self {
        return new self(
            'observation', null, $latenceMs,
            $modeleVersion, $probabilite, $facteurs, $explication, $confiance, $limites,
        );
    }

    public function estObservation(): bool
    {
        return $this->mode === 'observation';
    }
}
