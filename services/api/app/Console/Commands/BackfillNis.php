<?php

namespace App\Console\Commands;

use App\Models\MembreFamille;
use App\Services\Nis\AttributeurNis;
use Illuminate\Console\Command;

/**
 * P6.1 — Attribution d'un NIS aux dossiers créés avant la migration (CDC_09 §3).
 *
 * IDEMPOTENTE : ne traite que les dossiers dont `nis` est NULL. Un rejeu ne réattribue rien
 * et ne consomme pas la séquence (garantie portée par `AttributeurNis`, pas par cette commande).
 *
 * ORDRE STABLE : traitement par `id` croissant, donc par ancienneté de création. Les dossiers
 * les plus anciens reçoivent les plus petits compteurs — l'ordre des NIS reflète l'historique
 * réel, ce qui rend le backfill reproductible et vérifiable.
 *
 * Exécutée comme processus admin séparé (Twelve-Factor XII, CDC_12 §10) :
 *   XDEBUG_MODE=off php artisan masante:nis:backfill --dry-run
 */
class BackfillNis extends Command
{
    protected $signature = 'masante:nis:backfill
                            {--dry-run : Compte les dossiers concernés sans rien écrire}
                            {--pays=CI : Code pays à appliquer aux dossiers sans pays_code}
                            {--chunk=200 : Taille des lots}';

    protected $description = "Attribue un Identifiant National de Santé aux dossiers qui n'en ont pas (CDC_09 §3)";

    public function handle(AttributeurNis $attributeur): int
    {
        $pays    = strtoupper((string) $this->option('pays'));
        $chunk   = max(1, (int) $this->option('chunk'));
        $simule  = (bool) $this->option('dry-run');

        $restants = MembreFamille::whereNull('nis')->count();

        if ($restants === 0) {
            $this->info('Aucun dossier sans NIS — rien à faire.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s : %d dossier(s) sans NIS, pays « %s ».',
            $simule ? 'SIMULATION' : 'ATTRIBUTION',
            $restants,
            $pays
        ));

        if ($simule) {
            $this->warn('Mode --dry-run : aucune écriture effectuée.');

            return self::SUCCESS;
        }

        $barre    = $this->output->createProgressBar($restants);
        $traites  = 0;
        $echecs   = 0;

        // `chunkById` et non `chunk` : la condition de filtrage (`nis IS NULL`) devient fausse
        // au fur et à mesure des écritures — une pagination par OFFSET sauterait des lignes.
        MembreFamille::whereNull('nis')
            ->orderBy('id')
            ->chunkById($chunk, function ($lot) use ($attributeur, $pays, &$traites, &$echecs, $barre) {
                foreach ($lot as $membre) {
                    try {
                        if ($membre->pays_code === null) {
                            $membre->forceFill(['pays_code' => $pays])->save();
                        }

                        $attributeur->attribuer($membre, AttributeurNis::MOTIF_BACKFILL);
                        $traites++;
                    } catch (\Throwable $e) {
                        // Un dossier en échec ne doit pas interrompre le lot : on journalise
                        // et on continue, le rejeu de la commande reprendra les manquants.
                        $echecs++;
                        $this->newLine();
                        $this->error("Dossier #{$membre->getKey()} : {$e->getMessage()}");
                    }

                    $barre->advance();
                }
            });

        $barre->finish();
        $this->newLine(2);
        $this->info("NIS attribués : {$traites}");

        if ($echecs > 0) {
            $this->warn("Échecs : {$echecs} — relancez la commande pour reprendre.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
