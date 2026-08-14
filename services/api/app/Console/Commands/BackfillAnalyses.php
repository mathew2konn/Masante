<?php

namespace App\Console\Commands;

use App\Models\Analyse;
use App\Services\Analyse\AttributeurCodeAnalyse;
use Illuminate\Console\Command;

/**
 * P6.7a — Attribution du code national aux analyses du catalogue (CDC_09 §7.3).
 *
 * IDEMPOTENTE : la garantie vient de `AttributeurCodeAnalyse`, pas de cette commande. Une entrée qui
 * a déjà un code le conserve, et la séquence n'est pas consommée — un rejeu ne crée aucun trou.
 *
 * ORDRE STABLE par `id` croissant, comme en P6.1, P6.4a, P6.5a et P6.6a : le backfill est
 * reproductible d'une base à l'autre.
 *
 * CE QU'ELLE NE FAIT PAS : elle ne devine ni unité, ni milieu prélevé, ni valeur de référence. Ces
 * colonnes restent vides tant qu'une source ne les a pas renseignées — inventer une plage de
 * référence serait produire une donnée FAUSSE là où il n'y a qu'une donnée MANQUANTE, et le
 * contrôle qualité est là pour signaler le manque.
 *
 *   XDEBUG_MODE=off php artisan masante:analyses:backfill --dry-run
 */
class BackfillAnalyses extends Command
{
    protected $signature = 'masante:analyses:backfill
                            {--dry-run : Compte les entrées concernées sans rien écrire}
                            {--pays=CI : Code pays à appliquer aux entrées sans pays_code}';

    protected $description = 'Attribue un code national aux analyses du catalogue (CDC_09 §7.3)';

    public function handle(AttributeurCodeAnalyse $attributeur): int
    {
        $pays   = strtoupper((string) $this->option('pays'));
        $simule = (bool) $this->option('dry-run');

        $sansCode = Analyse::whereNull('code')->count();

        if ($sansCode === 0) {
            $this->info('Toutes les analyses ont un code national — rien à faire.');

            return self::SUCCESS;
        }

        if ($simule) {
            $this->info("{$sansCode} analyse(s) recevraient un code national (pays {$pays}).");

            return self::SUCCESS;
        }

        $this->info('Attribution en cours…');
        $codes = 0;

        Analyse::orderBy('id')->chunkById(100, function ($lot) use ($attributeur, $pays, &$codes) {
            foreach ($lot as $analyse) {
                if ($analyse->pays_code === null) {
                    $analyse->forceFill(['pays_code' => $pays])->save();
                }

                if ($analyse->code === null) {
                    $code = $attributeur->attribuer($analyse);
                    $codes++;
                    $this->line("  {$code}  ←  {$analyse->designation}");
                }
            }
        });

        $this->newLine();
        $this->info("{$codes} code(s) national/nationaux attribué(s).");

        return self::SUCCESS;
    }
}
