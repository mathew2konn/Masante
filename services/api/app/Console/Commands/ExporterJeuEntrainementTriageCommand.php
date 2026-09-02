<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Triage\ServiceExportJeuEntrainement;
use Illuminate\Console\Command;

/**
 * P10c-3-i (F19) — produit un export anonymisé du jeu d'apprentissage triage (CDC_05 §7.2).
 *
 * `--utilisateur` est OBLIGATOIRE, sans valeur par défaut : cet export est une action de
 * gouvernance attribuée à un compte réel habilité (`ia_triage.valider`), jamais une opération
 * système anonyme — même exigence que la commande, que l'écran, exécutée depuis le portail.
 *
 *   XDEBUG_MODE=off php artisan masante:triage:jeu-entrainement:exporter --utilisateur=1
 */
class ExporterJeuEntrainementTriageCommand extends Command
{
    protected $signature = 'masante:triage:jeu-entrainement:exporter
        {--utilisateur= : ID du compte habilité qui déclenche cet export}
        {--pays=CI : Code pays (2 lettres) — voir la limite du service sur pays_code}';

    protected $description = "Produit un export anonymisé du jeu d'apprentissage triage, prêt à entraîner";

    public function handle(ServiceExportJeuEntrainement $service): int
    {
        $utilisateurId = $this->option('utilisateur');

        if ($utilisateurId === null) {
            $this->error('--utilisateur est obligatoire : un export est attribué à un compte réel.');

            return self::FAILURE;
        }

        $utilisateur = User::find($utilisateurId);

        if ($utilisateur === null) {
            $this->error("Aucun utilisateur d'identifiant {$utilisateurId}.");

            return self::FAILURE;
        }

        try {
            $export = $service->exporter($utilisateur, (string) $this->option('pays'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Export #%d (%s, v%d) : %d ligne(s), k estimé = %s.',
            $export->id,
            $export->pays_code,
            $export->numero_export,
            $export->nb_lignes,
            $export->k_estime !== null ? (string) $export->k_estime : 'n/a',
        ));

        return self::SUCCESS;
    }
}
