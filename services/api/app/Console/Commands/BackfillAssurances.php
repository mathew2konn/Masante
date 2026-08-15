<?php

namespace App\Console\Commands;

use App\Models\OrganismeAssurance;
use App\Services\Assurance\AttributeurCodeOrganisme;
use Illuminate\Console\Command;

/**
 * P6.8d — Attribution du code national aux organismes d'assurance (CDC_09 §8).
 *
 * IDEMPOTENTE : la garantie vient de `AttributeurCodeOrganisme`, pas de cette commande. Une entrée
 * qui a déjà un code le conserve, et la séquence n'est pas consommée — un rejeu ne crée aucun trou.
 *
 * ORDRE STABLE par `id` croissant, comme en P6.1, P6.4a, P6.5a, P6.6a, P6.7a, P6.8b et P6.8c : le
 * backfill est reproductible d'une base à l'autre.
 *
 * L'APERÇU ANNONCE EXACTEMENT CE QUE FERA LE PASSAGE RÉEL. Le G2 de P6.8a a trouvé l'inverse — un
 * `--dry-run` annonçait « 0 praticien » avant que le passage réel n'en rattache 28. *Un aperçu qui
 * sous-estime ce qu'il va faire n'aide pas à décider.*
 *
 *   XDEBUG_MODE=off php artisan masante:assurances:backfill --dry-run
 */
class BackfillAssurances extends Command
{
    protected $signature = 'masante:assurances:backfill
                            {--dry-run : Compte les entrées concernées sans rien écrire}';

    protected $description = 'Attribue un code national aux organismes d\'assurance agréés (CDC_09 §8)';

    public function handle(AttributeurCodeOrganisme $attributeur): int
    {
        $simule   = (bool) $this->option('dry-run');
        $sansCode = OrganismeAssurance::whereNull('code')->count();

        if ($sansCode === 0) {
            $this->info('Tous les organismes ont un code national — rien à faire.');
            $this->ligneDesTemoins();

            return self::SUCCESS;
        }

        if ($simule) {
            $this->info("{$sansCode} organisme(s) recevraient un code national.");
            $this->ligneDesTemoins();

            return self::SUCCESS;
        }

        $this->info('Attribution en cours…');
        $codes = 0;

        OrganismeAssurance::orderBy('id')->chunkById(100, function ($lot) use ($attributeur, &$codes) {
            foreach ($lot as $organisme) {
                if ($organisme->code === null) {
                    $code = $attributeur->attribuer($organisme);
                    $codes++;
                    $this->line("  {$code}  ←  [{$organisme->pays_code}] {$organisme->nom}");
                }
            }
        });

        $this->newLine();
        $this->info("{$codes} code(s) national/nationaux attribué(s).");
        $this->ligneDesTemoins();

        return self::SUCCESS;
    }

    /**
     * Les témoins de l'honnêteté du contenu (motif `loinc` P6.7a, `code_cim10` P6.8c) : ce qui
     * manque est COMPTÉ, jamais tu — et n'empêche jamais la publication.
     */
    private function ligneDesTemoins(): void
    {
        $demo         = OrganismeAssurance::where('source', 'demonstration')->count();
        $sansAgrement = OrganismeAssurance::whereNull('numero_agrement')->count();

        if ($demo > 0) {
            $this->warn("{$demo} organisme(s) proviennent d'un jeu de DÉMONSTRATION : leur présence "
                .'dans ce registre ne prouve aucun agrément.');
        }

        if ($sansAgrement > 0) {
            $this->warn("{$sansAgrement} organisme(s) n'ont aucun numéro d'agrément enregistré. Ce "
                .'n\'est pas bloquant — aucun numéro d\'agrément réel n\'a été chargé dans ce '
                .'projet, et l\'exiger rendrait le référentiel impubliable.');
        }
    }
}
