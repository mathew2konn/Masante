<?php

namespace App\Console\Commands;

use App\Models\Medicament;
use App\Services\Medicament\AttributeurCodeMedicament;
use Illuminate\Console\Command;

/**
 * P6.6a — Attribution du code national aux médicaments qui n'en ont pas (CDC_09 §6.2).
 *
 * IDEMPOTENTE : la garantie est portée par `AttributeurCodeMedicament`, pas par cette commande. Un
 * produit qui a déjà un code le conserve, et la séquence n'est pas consommée — un rejeu ne crée
 * donc aucun trou dans la numérotation.
 *
 * ORDRE STABLE : par `id` croissant, donc par ancienneté. Les plus anciennes fiches reçoivent les
 * plus petits codes — même motif qu'en P6.1, P6.4a et P6.5a, ce qui rend le backfill reproductible
 * d'une base à l'autre.
 *
 * CE QU'ELLE NE FAIT PAS : elle ne devine ni forme, ni dosage, ni voie d'administration. Ces
 * colonnes restent NULL tant qu'une autorité ne les a pas renseignées. Inventer « comprimé » sur un
 * sirop produirait une donnée FAUSSE là où il n'y a qu'une donnée MANQUANTE, et le contrôle qualité
 * du référentiel est justement là pour signaler les manques.
 *
 *   XDEBUG_MODE=off php artisan masante:medicaments:backfill --dry-run
 */
class BackfillMedicaments extends Command
{
    protected $signature = 'masante:medicaments:backfill
                            {--dry-run : Compte les fiches concernées sans rien écrire}
                            {--pays=CI : Code pays à appliquer aux fiches sans pays_code}';

    protected $description = 'Attribue un code national aux médicaments du référentiel (CDC_09 §6.2)';

    public function handle(AttributeurCodeMedicament $attributeur): int
    {
        $pays   = strtoupper((string) $this->option('pays'));
        $simule = (bool) $this->option('dry-run');

        $sansCode = Medicament::whereNull('code')->count();

        if ($sansCode === 0) {
            $this->info('Tous les médicaments ont un code national — rien à faire.');

            return self::SUCCESS;
        }

        if ($simule) {
            $this->info("{$sansCode} médicament(s) recevraient un code national (pays {$pays}).");

            return self::SUCCESS;
        }

        $this->info('Attribution en cours…');
        $codes = 0;

        Medicament::orderBy('id')->chunkById(100, function ($lot) use ($attributeur, $pays, &$codes) {
            foreach ($lot as $medicament) {
                // Le pays de la fiche prime ; l'option ne sert qu'aux lignes muettes.
                if ($medicament->pays_code === null) {
                    $medicament->forceFill(['pays_code' => $pays])->save();
                }

                if ($medicament->code === null) {
                    $code = $attributeur->attribuer($medicament);
                    $codes++;
                    $this->line("  {$code}  ←  {$medicament->libelle}");
                }
            }
        });

        $this->newLine();
        $this->info("{$codes} code(s) national/nationaux attribué(s).");

        return self::SUCCESS;
    }
}
