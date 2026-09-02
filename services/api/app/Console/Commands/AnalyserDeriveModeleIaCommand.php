<?php

namespace App\Console\Commands;

use App\Services\Triage\ServiceDeriveModeleIa;
use Illuminate\Console\Command;

/**
 * P10c-3-ii lot B (F37→F39) — Le rapport de dérive quotidien (CDC_05 §8).
 *
 * ═══ UNE COMMANDE, PAS UN ENDPOINT ═══
 *
 * Le §8 demande des « alertes automatiques ». Un rapport quotidien est une tâche d'exploitation :
 * l'exposer en HTTP inviterait à le déclencher depuis un navigateur, et rien dans ce calcul n'a
 * besoin d'un acteur humain — contrairement à l'activation d'un modèle, qui en exige un et nommé.
 *
 * ═══ ELLE NE DÉSACTIVE RIEN (F39) ═══
 *
 * Elle constate et prévient. Un modèle qui dérive reste en service jusqu'à ce qu'un humain en
 * décide, avec le rollback de F24 — la ligne tenue depuis ADR-017.
 */
class AnalyserDeriveModeleIaCommand extends Command
{
    protected $signature = 'masante:triage:modele:derive
                            {--pays=CI : Le code pays du modèle en service}';

    protected $description = 'Compare la population d\'aujourd\'hui à celle de l\'apprentissage et signale les dérives (CDC_05 §8)';

    public function handle(ServiceDeriveModeleIa $service): int
    {
        $rapport = $service->analyser((string) $this->option('pays'));

        // ═══ CHAQUE ISSUE EST DITE, AUCUNE N'EST SILENCIEUSE ═══
        //
        // « Aucun modèle actif » et « aucune dérive » se ressemblent à l'écran si on les tait tous
        // les deux — et ce sont deux situations opposées : dans l'une il n'y a rien à surveiller,
        // dans l'autre la surveillance a eu lieu et n'a rien trouvé.
        match ($rapport['statut']) {
            'aucun_modele_actif' => $this->warn(
                'Aucun modèle en service pour ce pays : il n\'y a rien à surveiller.'),
            'echantillon_insuffisant' => $this->warn(sprintf(
                'Échantillon insuffisant (%d ligne(s) de référence, %d observée(s)) : aucun indice '
                .'n\'est calculé plutôt qu\'un chiffre qui ne voudrait rien dire.',
                $rapport['nb_reference'], $rapport['nb_observees'])),
            default => $this->rendreAnalyse($rapport),
        };

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $rapport */
    private function rendreAnalyse(array $rapport): void
    {
        $this->info(sprintf(
            'Modèle version %d — %d ligne(s) de référence contre %d observée(s).',
            $rapport['version'], $rapport['nb_reference'], $rapport['nb_observees'],
        ));

        if ($rapport['alertes'] === 0) {
            $this->line('Aucune dérive au-delà des seuils. Rien n\'est écrit : une absence d\'alerte '
                .'se lit à l\'absence de ligne.');

            return;
        }

        $this->warn($rapport['alertes'].' dérive(s) constatée(s) — le modèle reste EN SERVICE :');

        foreach ($rapport['detail'] as $alerte) {
            $this->line(sprintf('  [%s] %s : %s (%s)',
                $alerte['nature'], $alerte['indicateur'], $alerte['valeur'], $alerte['niveau']));
        }

        $this->line('La décision appartient à un humain (rollback disponible).');
    }
}
