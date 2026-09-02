<?php

namespace App\Console\Commands;

use App\Services\ServiceNotification;
use Illuminate\Console\Command;

/**
 * Lot 9 (post-facturation) — relance les factures patient A_REGLER dont l'échéance est dépassée
 * et qui n'ont jamais été relancées.
 *
 * Toute la décision vit dans {@see ServiceNotification::relancerFacturesEnRetard()} (UNE SEULE
 * relance par facture, R18) ; cette commande n'est qu'un point d'entrée CLI/planifié, même motif
 * que `masante:recouvrement:verifier-echeances` (lot 1). Planification : `routes/console.php`.
 *
 *   XDEBUG_MODE=off php artisan masante:facturation:relancer-patients
 */
class RelancerFacturesPatientCommand extends Command
{
    protected $signature = 'masante:facturation:relancer-patients';

    protected $description = 'Relance (une seule fois) les factures patient en retard de règlement';

    public function handle(ServiceNotification $notifications): int
    {
        $nombre = $notifications->relancerFacturesEnRetard();

        $this->info("Relances de facturation patient envoyées : {$nombre}.");

        return self::SUCCESS;
    }
}
