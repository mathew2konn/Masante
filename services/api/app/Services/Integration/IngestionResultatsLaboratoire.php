<?php

namespace App\Services\Integration;

use App\Models\Automate;
use App\Models\ClientApi;
use App\Models\JournalIngestion;
use App\Models\Prelevement;
use App\Services\Analyse\ServiceValidationBiologique;
use Illuminate\Validation\ValidationException;

/**
 * B5-c (L10 réécrit, M9) — Ingestion des résultats d'un automate biologique (CDC_11 §8.1).
 *
 * ═══ MÊME PATRON QUE L'INGESTION DU STOCK D'OFFICINE (P11.2), ET C'EST DÉLIBÉRÉ ═══
 *
 * **L'API est un contrat d'échange, jamais un second chemin d'écriture** (ADR-030). Ce service
 * n'écrit rien lui-même : il résout l'automate et le prélèvement désignés, puis appelle
 * {@see ServiceValidationBiologique::importer()} — LE MÊME service que la saisie manuelle du
 * laborantin au portail. La seule chose que l'ingestion ajoute est de désigner PAR QUEL AUTOMATE,
 * information que le portail n'a pas à porter.
 *
 * ═══ LE SERVEUR NE DEVINE JAMAIS (L10) ═══
 *
 * Le rattachement se fait par l'IDENTIFIANT DU PRÉLÈVEMENT — l'étiquette du tube — jamais par un
 * rapprochement de nom de patient : *ce serait l'erreur d'identification que le §7.4 existe pour
 * supprimer*. Un identifiant inconnu est refusé et NOMMÉ (patron P11.2 : « le serveur ne devine
 * pas une référence produit », transposé à un prélèvement).
 *
 * ═══ UN AUTOMATE NE VALIDE JAMAIS ═══
 *
 * `importer()` (appelé ici) ne fait qu'écrire le BROUILLON (`resultats_bruts_json`) — le
 * prélèvement reste `en_analyse`. La validation biologique humaine (`analyse.valider`) reste, dans
 * tous les cas, un acte séparé, au portail.
 *
 * ═══ ACCEPTATION PARTIELLE, RAPPORT NOMINATIF (précédent P11.2) ═══
 *
 * Un lot dont une ligne échoue écrit les autres et NOMME la fautive avec son motif.
 */
class IngestionResultatsLaboratoire
{
    public const DOMAINE = 'resultats_laboratoire';

    public function __construct(private readonly ServiceValidationBiologique $validation) {}

    /**
     * @param  array<int, array<string, mixed>>  $resultats
     * @return array{journal: JournalIngestion, rejeu: bool}
     */
    public function ingerer(ClientApi $client, int $automateId, array $resultats, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null) {
            $deja = JournalIngestion::query()
                ->where('client_api_id', $client->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($deja !== null) {
                return ['journal' => $deja, 'rejeu' => true];
            }
        }

        // Un `automate_id` faux ou d'un autre laboratoire fait échouer le LOT ENTIER — c'est une
        // question d'AUTORISATION, pas une ligne à refuser parmi d'autres.
        $automate = Automate::find($automateId);

        if ($automate === null || ! $automate->appartientA($client->structure_id) || ! $automate->actif) {
            throw ValidationException::withMessages([
                'automate_id' => ['Automate inconnu, désactivé, ou n\'appartenant pas à ce laboratoire.'],
            ]);
        }

        $acceptees = 0;
        $refus = [];

        foreach ($resultats as $index => $ligne) {
            $identifiant = trim((string) ($ligne['identifiant_prelevement'] ?? ''));
            $valeurs = is_array($ligne['valeurs'] ?? null) ? $ligne['valeurs'] : [];

            try {
                if ($identifiant === '') {
                    throw ValidationException::withMessages(['identifiant_prelevement' => ['Identifiant absent.']]);
                }

                $prelevement = Prelevement::query()->where('identifiant', $identifiant)->first();

                if ($prelevement === null) {
                    throw ValidationException::withMessages([
                        'identifiant_prelevement' => [
                            'Prélèvement « '.$identifiant.' » inconnu : aucun rapprochement n\'est tenté.',
                        ],
                    ]);
                }

                $this->validation->importer($automate, $prelevement, $valeurs);
                $acceptees++;
            } catch (\Throwable $e) {
                $refus[] = ['index' => $index, 'identifiant_prelevement' => $identifiant, 'motif' => $e->getMessage()];
            }
        }

        $journal = JournalIngestion::create([
            'client_api_id' => $client->id,
            'structure_id' => $client->structure_id,
            'domaine' => self::DOMAINE,
            'idempotency_key' => $idempotencyKey,
            'lignes_recues' => count($resultats),
            'lignes_acceptees' => $acceptees,
            'lignes_refusees' => count($refus),
            'refus_json' => $refus === [] ? null : $refus,
            'rejeu' => false,
        ]);

        return ['journal' => $journal, 'rejeu' => false];
    }
}
