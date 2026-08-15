<?php

namespace App\Console\Commands;

use App\Models\Vaccin;
use App\Services\Vaccin\AttributeurCodeVaccin;
use Illuminate\Console\Command;

/**
 * P6.8b — Attribution du code national aux vaccins du référentiel (CDC_09 §8).
 *
 * IDEMPOTENTE : la garantie vient de `AttributeurCodeVaccin`, pas de cette commande. Une entrée qui
 * a déjà un code le conserve, et la séquence n'est pas consommée — un rejeu ne crée aucun trou.
 *
 * ORDRE STABLE par `id` croissant, comme en P6.1, P6.4a, P6.5a, P6.6a et P6.7a : le backfill est
 * reproductible d'une base à l'autre.
 *
 * CE QU'ELLE NE FAIT PAS : elle ne devine ni échéance, ni caractère obligatoire, ni provenance. Un
 * vaccin sans calendrier reste sans calendrier, et le contrôle qualité le SIGNALE à la publication
 * — inventer une échéance produirait une donnée FAUSSE là où il n'y a qu'une donnée MANQUANTE, et
 * une fausse échéance vaccinale enverrait un parent à un rendez-vous qui n'existe pas.
 *
 *   XDEBUG_MODE=off php artisan masante:vaccins:backfill --dry-run
 */
class BackfillVaccins extends Command
{
    protected $signature = 'masante:vaccins:backfill
                            {--dry-run : Compte les entrées concernées sans rien écrire}
                            {--pays=CI : Code pays à appliquer aux entrées sans pays_code}';

    protected $description = 'Attribue un code national aux vaccins du référentiel (CDC_09 §8)';

    public function handle(AttributeurCodeVaccin $attributeur): int
    {
        $pays   = strtoupper((string) $this->option('pays'));
        $simule = (bool) $this->option('dry-run');

        $sansCode = Vaccin::whereNull('code')->count();

        // L'APERÇU ANNONCE EXACTEMENT CE QUE FERA LE PASSAGE RÉEL. Le G2 de P6.8a a trouvé
        // l'inverse : un `--dry-run` annonçait « 0 praticien » avant que le passage réel n'en
        // rattache 28, parce qu'il comptait un état que la simulation n'atteignait pas. *Un aperçu
        // qui sous-estime ce qu'il va faire n'aide pas à décider.*
        if ($sansCode === 0) {
            $this->info('Tous les vaccins ont un code national — rien à faire.');

            return self::SUCCESS;
        }

        if ($simule) {
            $this->info("{$sansCode} vaccin(s) recevraient un code national (pays {$pays}).");

            return self::SUCCESS;
        }

        $this->info('Attribution en cours…');
        $codes = 0;

        Vaccin::orderBy('id')->chunkById(100, function ($lot) use ($attributeur, $pays, &$codes) {
            foreach ($lot as $vaccin) {
                if ($vaccin->pays_code === null) {
                    $vaccin->forceFill(['pays_code' => $pays])->save();
                }

                if ($vaccin->code === null) {
                    $code = $attributeur->attribuer($vaccin);
                    $codes++;
                    $this->line("  {$code}  ←  {$vaccin->libelle}");
                }
            }
        });

        $this->newLine();
        $this->info("{$codes} code(s) national/nationaux attribué(s).");

        return self::SUCCESS;
    }
}
