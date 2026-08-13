<?php

namespace App\Console\Commands;

use App\Models\StructureSanitaire;
use App\Services\Etablissement\AttributeurIdentifiantEtablissement;
use Illuminate\Console\Command;

/**
 * P6.4 — Attribution de l'identifiant national aux établissements qui n'en ont pas (CDC_09 §4.3).
 *
 * IDEMPOTENTE : ne traite que les établissements dont `identifiant_national` est NULL. Un rejeu
 * ne réattribue rien et ne consomme pas la séquence — garantie portée par
 * `AttributeurIdentifiantEtablissement`, pas par cette commande.
 *
 * ORDRE STABLE : traitement par `id` croissant, donc par ancienneté de création. Les plus anciens
 * établissements reçoivent les plus petits numéros, ce qui rend le backfill reproductible et
 * vérifiable — même motif qu'en P6.1 pour le NIS.
 *
 * Exécutée comme processus admin séparé (Twelve-Factor XII, CDC_12 §10) :
 *   XDEBUG_MODE=off php artisan masante:etablissement:backfill --dry-run
 */
class BackfillIdentifiantsEtablissements extends Command
{
    protected $signature = 'masante:etablissement:backfill
                            {--dry-run : Compte les établissements concernés sans rien écrire}
                            {--pays=CI : Code pays à appliquer aux établissements sans pays_code}';

    protected $description = "Attribue un identifiant national aux établissements qui n'en ont pas (CDC_09 §4.3)";

    public function handle(AttributeurIdentifiantEtablissement $attributeur): int
    {
        $pays = strtoupper((string) $this->option('pays'));
        $simule = (bool) $this->option('dry-run');

        $restants = StructureSanitaire::whereNull('identifiant_national')->count();

        if ($restants === 0) {
            $this->info('Aucun établissement sans identifiant national — rien à faire.');

            return self::SUCCESS;
        }

        if ($simule) {
            $this->info("{$restants} établissement(s) recevraient un identifiant national (pays {$pays}).");

            return self::SUCCESS;
        }

        $this->info("Attribution en cours pour {$restants} établissement(s)…");
        $traites = 0;

        StructureSanitaire::whereNull('identifiant_national')
            ->orderBy('id')
            ->chunkById(100, function ($lot) use ($attributeur, $pays, &$traites) {
                foreach ($lot as $etablissement) {
                    // Le pays de l'établissement prime ; l'option ne sert qu'aux lignes muettes.
                    if ($etablissement->pays_code === null) {
                        $etablissement->forceFill(['pays_code' => $pays])->save();
                    }

                    $identifiant = $attributeur->attribuer($etablissement);
                    $traites++;

                    $this->line("  {$identifiant}  ←  {$etablissement->nom}");
                }
            });

        $this->newLine();
        $this->info("{$traites} identifiant(s) attribué(s).");

        return self::SUCCESS;
    }
}
