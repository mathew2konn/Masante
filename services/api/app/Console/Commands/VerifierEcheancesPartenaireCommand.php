<?php

namespace App\Console\Commands;

use App\Services\RecouvrementPartenaireService;
use Illuminate\Console\Command;

/**
 * Lot 1 (facturation partenaire) — bascule au Palier 0 les structures dont une facture est échue
 * depuis 30 jours ou plus et encore soldée positivement.
 *
 * Toute la décision vit dans {@see RecouvrementPartenaireService::verifierEcheances()} ; cette
 * commande n'est qu'un point d'entrée CLI/planifié, comme `masante:vaccins:echeances` pour le
 * calendrier vaccinal. Enregistrement de la planification : `routes/console.php`.
 *
 *   XDEBUG_MODE=off php artisan masante:recouvrement:verifier-echeances
 */
class VerifierEcheancesPartenaireCommand extends Command
{
    protected $signature = 'masante:recouvrement:verifier-echeances';

    protected $description = 'Bascule au Palier 0 les structures partenaires impayées depuis 30 jours ou plus';

    public function handle(RecouvrementPartenaireService $service): int
    {
        $service->verifierEcheances();

        $this->info('Vérification des échéances partenaires effectuée.');

        return self::SUCCESS;
    }
}
