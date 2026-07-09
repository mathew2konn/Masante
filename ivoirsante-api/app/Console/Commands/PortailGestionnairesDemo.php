<?php

namespace App\Console\Commands;

use App\Models\ActivationPortail;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Module 4 / 4.2 — Outil de DÉMO/DEV : rattache un gestionnaire à chaque établissement et affiche son
 * lien d'activation, pour tester le flux CdC §5.4.1 sans passerelle mail (comme l'OTP simulé).
 *
 * Reproduit exactement le comportement du portail : compte créé SANS mot de passe, rôle
 * `gestionnaire_etablissement`, rattaché à l'établissement, lien d'activation à usage unique (24h).
 * Idempotent : si un gestionnaire existe déjà, on régénère seulement son lien (l'ancien est invalidé).
 * NE PAS utiliser en production (crée des comptes de démonstration).
 */
class PortailGestionnairesDemo extends Command
{
    protected $signature = 'portail:gestionnaires-demo
        {--base=http://localhost:8000 : Base URL pour des liens cliquables (ex. votre URL Ngrok)}';

    protected $description = 'Crée un gestionnaire par établissement et affiche les liens d\'activation (DEV).';

    public function handle(): int
    {
        $base = rtrim((string) $this->option('base'), '/');
        $structures = StructureSanitaire::orderBy('id')->get();

        if ($structures->isEmpty()) {
            $this->warn('Aucun établissement en base. Seedez d\'abord les structures (Module 3).');

            return self::SUCCESS;
        }

        $lignes = [];

        foreach ($structures as $structure) {
            $gestionnaire = $structure->staff()->role('gestionnaire_etablissement')->first();

            if (! $gestionnaire) {
                $gestionnaire = User::create([
                    'prenom'       => 'Gestionnaire',
                    'nom'          => Str::limit($structure->nom, 90, ''),
                    'email'        => "gestionnaire{$structure->id}@masante.ci",
                    'password'     => null,               // aucun mot de passe temporaire (§5.4.1)
                    'structure_id' => $structure->id,
                    'actif'        => true,
                ]);
                $gestionnaire->assignRole('gestionnaire_etablissement');
                $etat = 'créé';
            } elseif ($gestionnaire->password !== null) {
                // Déjà activé : on ne régénère pas de lien (le compte a déjà son mot de passe).
                $lignes[] = [$structure->nom, $gestionnaire->email, '(déjà activé)'];
                continue;
            } else {
                $etat = 'lien régénéré';
            }

            $token = ActivationPortail::genererPour($gestionnaire);
            $lien = $base . route('portail.activation.show', ['token' => $token], false);

            $lignes[] = [$structure->nom, $gestionnaire->email, $lien];
            $this->line("<info>[{$etat}]</info> {$structure->nom} → {$gestionnaire->email}");
        }

        $this->newLine();
        $this->table(['Établissement', 'Gestionnaire (e-mail)', 'Lien d\'activation (24h, usage unique)'], $lignes);
        $this->newLine();
        $this->info('Ouvrez un lien pour poser le mot de passe, puis connectez-vous sur ' . $base . '/portail/login');

        return self::SUCCESS;
    }
}
