<?php

namespace App\Console\Commands;

use App\Models\Automate;
use App\Models\ClientApi;
use App\Models\StructureSanitaire;
use App\Services\Integration\AuthentificationClientApi;
use Illuminate\Console\Command;

/**
 * B5-c (L10 réécrit, M9) — Déclare ou désactive un automate biologique d'un laboratoire.
 *
 * ═══ POURQUOI UNE COMMANDE, ET PAS UN ÉCRAN ═══
 *
 * Même raisonnement qu'{@see EmettreClientApiCommand} (P11.2) : déclarer un appareil qui écrira
 * dans des dossiers patients est un acte d'exploitation, vérifié hors du système — pas une saisie
 * de routine qu'un gestionnaire ferait seul dans un formulaire.
 *
 * `--client` est FACULTATIF : il ne fait que TRACER sous quelle clé cet automate pousse, il
 * n'authentifie rien lui-même. L'authentification de chaque envoi reste entièrement portée par le
 * HMAC ({@see AuthentificationClientApi}) — le serveur vérifie
 * seulement, à l'ingestion, que l'automate désigné par la charge appartient à LA MÊME structure
 * que le client authentifié.
 */
class DeclarerAutomateCommand extends Command
{
    protected $signature = 'masante:laboratoire:automate
                            {structure : Identifiant du laboratoire}
                            {libelle : Nom de l\'appareil (ex. « Analyseur Sysmex XN-550 »)}
                            {--marque=}
                            {--modele=}
                            {--serie=}
                            {--client= : Identifiant du client API qui pousse pour cet automate}
                            {--desactiver= : Identifiant d\'automate à désactiver au lieu de déclarer}';

    protected $description = 'Déclare ou désactive un automate biologique (CDC_11 §8.1, CDC_04 §109)';

    public function handle(): int
    {
        if ($this->option('desactiver') !== null) {
            return $this->desactiver();
        }

        return $this->declarer();
    }

    private function declarer(): int
    {
        $structure = StructureSanitaire::find($this->argument('structure'));

        if ($structure === null || ! $structure->estLaboratoire()) {
            $this->error('Aucun laboratoire ne correspond à cet identifiant.');

            return self::FAILURE;
        }

        $clientId = $this->option('client');
        $client = null;

        if ($clientId !== null) {
            $client = ClientApi::find($clientId);

            if ($client === null || $client->structure_id !== $structure->id) {
                $this->error('Ce client d\'API n\'existe pas ou n\'appartient pas à ce laboratoire.');

                return self::FAILURE;
            }
        }

        $automate = Automate::create([
            'structure_id' => $structure->id,
            'client_api_id' => $client?->id,
            'libelle' => (string) $this->argument('libelle'),
            'marque' => $this->option('marque'),
            'modele' => $this->option('modele'),
            'numero_serie' => $this->option('serie'),
        ]);

        $this->info('Automate déclaré pour « '.$structure->nom.' » — identifiant '.$automate->id.'.');

        return self::SUCCESS;
    }

    private function desactiver(): int
    {
        $automate = Automate::find($this->option('desactiver'));

        if ($automate === null) {
            $this->error('Automate introuvable.');

            return self::FAILURE;
        }

        $automate->forceFill(['actif' => false])->save();

        $this->info('Automate « '.$automate->libelle.' » désactivé : ses envois seront refusés dès maintenant.');

        return self::SUCCESS;
    }
}
