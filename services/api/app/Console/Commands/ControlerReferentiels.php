<?php

namespace App\Console\Commands;

use App\Models\Referentiel;
use App\Services\Referentiel\JournalReferentiel;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Support\RegistreReferentiels;
use Illuminate\Console\Command;

/**
 * P6.3 — Contrôle de l'état des référentiels nationaux (CDC_09 §10 qualité, §11 audit).
 *
 * DÉTECTION SEULE. Cette commande ne publie rien, ne corrige rien, ne répare aucune chaîne : elle
 * constate et rapporte. Même principe qu'en P5.3b-4 — un outil qui corrige tout seul ce qu'il
 * détecte finit par masquer ce qu'il aurait fallu voir. Ici, réparer une chaîne d'audit rompue
 * effacerait précisément ce qu'elle était censée prouver.
 *
 * Trois questions, une par section du rapport :
 *   1. le contenu ACTUEL de chaque table métier est-il publiable (§10) ?
 *   2. a-t-il divergé de la version en vigueur — c'est-à-dire quelqu'un a-t-il fait un `UPDATE`
 *      direct sans passer par la gouvernance ?
 *   3. la chaîne d'audit est-elle intacte (§11) ?
 *
 * La question 2 est la plus intéressante : elle mesure l'écart entre ce que le triage lit
 * réellement et ce que le référentiel publié affirme. Un écart n'est pas une faute — c'est
 * l'état normal entre deux publications — mais il doit être VU.
 *
 * Exécutée comme processus admin séparé (Twelve-Factor XII, CDC_12 §10) :
 *   XDEBUG_MODE=off php artisan masante:referentiel:controler
 */
class ControlerReferentiels extends Command
{
    protected $signature = 'masante:referentiel:controler
                            {--pays= : Code pays à contrôler (défaut : celui de la configuration)}';

    protected $description = 'Contrôle qualité, divergence et intégrité du journal des référentiels nationaux (CDC_09 §10/§11)';

    public function handle(ServiceGouvernanceReferentiel $gouvernance, JournalReferentiel $journal): int
    {
        $pays = strtoupper((string) ($this->option('pays') ?: config('referentiels.pays_defaut')));
        $anomalies = 0;

        $this->info("Référentiels nationaux — pays « {$pays} »");
        $this->newLine();

        foreach (RegistreReferentiels::codes() as $code) {
            $etat = $gouvernance->controler($code);
            $referentiel = Referentiel::query()->where('code', $code)->where('pays_code', $pays)->first();

            $this->line("  <options=bold>{$code}</> — {$etat['nb_entrees']} entrée(s)");

            // 1. Qualité du contenu actuel.
            if ($etat['erreurs'] === []) {
                $this->line('    qualité   : conforme');
            } else {
                $anomalies++;
                $this->line('    <fg=red>qualité   : '.count($etat['erreurs']).' anomalie(s)</>');
                foreach ($etat['erreurs'] as $erreur) {
                    $this->line("      · {$erreur}");
                }
            }

            // 2. Divergence entre la table métier et la version en vigueur.
            if ($referentiel === null) {
                $anomalies++;
                $this->line('    <fg=yellow>registre  : non enregistré (php artisan db:seed --class=ReferentielRegistreSeeder)</>');
            } elseif (! $referentiel->estPublie()) {
                $this->line('    <fg=yellow>version   : aucune version publiée — rien n\'est diffusé</>');
            } else {
                $publiee = $referentiel->versionPubliee();
                $identique = $publiee !== null && hash_equals($publiee->empreinte, $etat['empreinte']);

                $this->line($identique
                    ? "    version   : n°{$referentiel->version_publiee_numero}, conforme à la table"
                    : "    <fg=yellow>version   : n°{$referentiel->version_publiee_numero}, DIVERGENTE de la table "
                        .'(un changement attend d\'être proposé)</>');
            }

            $this->line('    empreinte : '.substr($etat['empreinte'], 0, 16).'…');
            $this->newLine();
        }

        // 3. Intégrité de la chaîne d'audit (§11).
        $chaine = $journal->verifierChaine();

        if ($chaine['intacte']) {
            $this->info("Journal d'audit : chaîne intacte ({$chaine['entrees']} entrée(s)).");
        } else {
            $anomalies++;
            $this->error("Journal d'audit : CHAÎNE ROMPUE — {$chaine['rupture']['message']}");
            $this->line("  type : {$chaine['rupture']['type']}");
        }

        // Sortie non nulle = quelque chose demande une décision humaine. Utilisable en CI ou en
        // tâche planifiée sans avoir à interpréter le texte du rapport.
        return $anomalies === 0 ? self::SUCCESS : self::FAILURE;
    }
}
