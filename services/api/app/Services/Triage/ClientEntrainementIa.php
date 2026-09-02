<?php

namespace App\Services\Triage;

use Illuminate\Support\Facades\Http;

/**
 * P10c-3-i (F19/F21) — Appelle `POST {triage-service}/api/v1/triage/entrainement`.
 *
 * ═══ AUCUN DISJONCTEUR, CONTRAIREMENT À {@see ClientTriageIa} — ET C'EST DÉLIBÉRÉ ═══
 *
 * Le disjoncteur de `/score` protège un appel fait à CHAQUE triage : sans lui, un service à terre
 * ferait payer un timeout à chaque patient. L'entraînement est déclenché rarement, par une action
 * humaine délibérée (commande Artisan ou écran de gouvernance) : un échec se rapporte à l'opérateur,
 * qui réessaie plus tard. Ajouter un disjoncteur ici protégerait une charge qui n'existe pas.
 *
 * ═══ AUCUNE POSTURE RÉSEAU RENFORCÉE (F21) ═══
 *
 * Même exposition que `/score` aujourd'hui : atteignable en interne seulement, sans principal
 * signé. Le motif existe déjà dans ce projet (P5.5b-1/P5.6a) si le propriétaire veut le durcir —
 * ce n'est pas ajouté par défaut pour ne pas dupliquer une machinerie sans besoin concret (le
 * service n'est, à ce stade, joignable que depuis Laravel).
 */
class ClientEntrainementIa
{
    /**
     * @param  array<int, array<string, mixed>>  $lignes  Déjà anonymisées (F20) — jamais de
     *                                                    `triage_id` ni d'identité dans ce tableau.
     * @return array{mlflow_run_id: string, nb_lignes_entrainement: int, nb_lignes_test: int, metriques: array<string, float>}
     *
     * @throws \RuntimeException volume insuffisant (422, double garde F15) ou service en échec.
     */
    public function entrainer(string $paysCode, int $numeroExport, array $lignes): array
    {
        $url = rtrim((string) config('masante.triage_ia.base_url'), '/').'/api/v1/triage/entrainement';

        $reponse = Http::timeout((float) config('masante.triage_ia.timeout_entrainement_s', 30))
            ->post($url, [
                'pays_code' => $paysCode,
                'numero_export' => $numeroExport,
                'lignes' => $lignes,
            ]);

        if ($reponse->status() === 422) {
            throw new \RuntimeException(
                (string) ($reponse->json('message') ?? 'triage-service refuse : volume insuffisant.')
            );
        }

        if (! $reponse->successful()) {
            throw new \RuntimeException(
                "triage-service a répondu {$reponse->status()} à une demande d'entraînement — "
                .'aucune version de gouvernance ne sera créée.'
            );
        }

        return $reponse->json();
    }
}
