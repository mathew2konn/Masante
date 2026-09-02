<?php

namespace App\Console\Commands;

use App\Http\Controllers\Portail\GouvernanceModeleIaController;
use App\Models\ExportJeuEntrainement;
use App\Models\User;
use App\Services\Triage\ServiceGouvernanceModeleIa;
use Illuminate\Console\Command;

/**
 * P10c-3-i (F19) — déclenche un entraînement réel sur un export déjà produit (CDC_05 §7.2/§8).
 *
 * `--utilisateur` est OBLIGATOIRE, sans valeur par défaut — même raison que la commande d'export :
 * le §9 exige une trace nominative de qui a déclenché l'entraînement, condition du quatre-yeux
 * appliqué ensuite par {@see GouvernanceModeleIaController::valider()}.
 *
 *   XDEBUG_MODE=off php artisan masante:triage:modele:entrainer 3 --utilisateur=1
 */
class EntrainerModeleTriageCommand extends Command
{
    protected $signature = 'masante:triage:modele:entrainer
        {export : Identifiant de l\'export (exports_jeu_entrainement.id)}
        {--utilisateur= : ID du compte habilité qui déclenche cet entraînement}';

    protected $description = 'Entraîne un modèle réel sur un export anonymisé — produit un candidat de gouvernance';

    public function handle(ServiceGouvernanceModeleIa $service): int
    {
        $utilisateurId = $this->option('utilisateur');

        if ($utilisateurId === null) {
            $this->error('--utilisateur est obligatoire : un entraînement est attribué à un compte réel.');

            return self::FAILURE;
        }

        $utilisateur = User::find($utilisateurId);

        if ($utilisateur === null) {
            $this->error("Aucun utilisateur d'identifiant {$utilisateurId}.");

            return self::FAILURE;
        }

        $export = ExportJeuEntrainement::find((int) $this->argument('export'));

        if ($export === null) {
            $this->error("Aucun export d'identifiant {$this->argument('export')}.");

            return self::FAILURE;
        }

        try {
            $version = $service->entrainer($utilisateur, $export);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Version %d (%s) créée en statut « %s » — run MLflow %s.',
            $version->numero_version,
            $version->pays_code,
            $version->statut,
            $version->mlflow_run_id,
        ));

        return self::SUCCESS;
    }
}
