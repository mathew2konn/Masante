<?php

namespace App\Console\Commands;

use App\Models\ExerciceProfessionnel;
use App\Models\Medecin;
use App\Services\Professionnel\AttributeurNumeroProfessionnel;
use Illuminate\Console\Command;

/**
 * P6.5a — Attribution du numéro national aux professionnels qui n'en ont pas, et report de leur
 * exercice principal dans `professionnel_etablissement` (CDC_09 §5.2).
 *
 * DEUX TRAVAUX, PAS UN, et ils sont ici ensemble parce qu'ils décrivent le même fait : rendre
 * existante dans le référentiel une fiche qui n'y était pas. Les séparer en deux commandes
 * laisserait une fenêtre où un professionnel a un numéro national mais aucun lieu d'exercice —
 * c'est-à-dire une entrée du référentiel qui ne répond pas à « où exerce-t-il ? ».
 *
 * IDEMPOTENTE dans les deux sens :
 *   · le numéro — garantie portée par `AttributeurNumeroProfessionnel`, pas par cette commande ;
 *   · l'exercice principal — `firstOrCreate` sur le couple `(medecin_id, structure_id)`, qui est
 *     UNIQUE en base. Un rejeu ne crée pas de doublon et ne réécrit pas une ligne modifiée depuis.
 *
 * ORDRE STABLE : par `id` croissant, donc par ancienneté. Les plus anciennes fiches reçoivent les
 * plus petits numéros — même motif qu'en P6.1 et P6.4a, ce qui rend le backfill reproductible.
 *
 *   XDEBUG_MODE=off php artisan masante:professionnels:backfill --dry-run
 */
class BackfillProfessionnels extends Command
{
    protected $signature = 'masante:professionnels:backfill
                            {--dry-run : Compte les fiches concernées sans rien écrire}
                            {--pays=CI : Code pays à appliquer aux fiches sans pays_code}';

    protected $description = 'Attribue un numéro national aux professionnels et reporte leur exercice principal (CDC_09 §5.2)';

    public function handle(AttributeurNumeroProfessionnel $attributeur): int
    {
        $pays   = strtoupper((string) $this->option('pays'));
        $simule = (bool) $this->option('dry-run');

        $sansNumero = Medecin::whereNull('numero_professionnel')->count();

        // Les fiches dont l'exercice principal n'est pas encore reporté. Le calcul est le même
        // que celui du traitement, sinon le `--dry-run` annoncerait autre chose que ce qui se
        // passera — un dry-run qui ment est pire que pas de dry-run.
        $sansExercice = Medecin::whereDoesntHave('exercices', fn ($q) => $q->whereColumn(
            'professionnel_etablissement.structure_id',
            'medecins.structure_id',
        ))->count();

        if ($sansNumero === 0 && $sansExercice === 0) {
            $this->info('Tous les professionnels ont un numéro national et un exercice principal — rien à faire.');

            return self::SUCCESS;
        }

        if ($simule) {
            $this->info("{$sansNumero} professionnel(s) recevraient un numéro national (pays {$pays}).");
            $this->info("{$sansExercice} exercice(s) principal/principaux seraient reportés.");

            return self::SUCCESS;
        }

        $this->info('Attribution en cours…');
        $numeros = 0;
        $exercices = 0;

        Medecin::orderBy('id')->chunkById(100, function ($lot) use ($attributeur, $pays, &$numeros, &$exercices) {
            foreach ($lot as $professionnel) {
                // Le pays de la fiche prime ; l'option ne sert qu'aux lignes muettes.
                if ($professionnel->pays_code === null) {
                    $professionnel->forceFill(['pays_code' => $pays])->save();
                }

                if ($professionnel->numero_professionnel === null) {
                    $numero = $attributeur->attribuer($professionnel);
                    $numeros++;
                    $this->line("  {$numero}  ←  {$professionnel->nom_complet}");
                }

                // Report de l'exercice principal. `firstOrCreate` et non `updateOrCreate` : si un
                // gestionnaire a déjà décrit cet exercice à la main (dates, service), le backfill
                // n'a rien à lui apprendre et ne doit pas écraser sa saisie.
                $exercice = ExerciceProfessionnel::firstOrCreate(
                    [
                        'medecin_id'   => $professionnel->id,
                        'structure_id' => $professionnel->structure_id,
                    ],
                    [
                        'service_id'    => $professionnel->service_id,
                        'est_principal' => true,
                        'actif'         => (bool) $professionnel->actif,
                    ],
                );

                if ($exercice->wasRecentlyCreated) {
                    $exercices++;
                }
            }
        });

        $this->newLine();
        $this->info("{$numeros} numéro(s) attribué(s), {$exercices} exercice(s) principal/principaux reporté(s).");

        return self::SUCCESS;
    }
}
