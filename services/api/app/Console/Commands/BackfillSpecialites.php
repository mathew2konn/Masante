<?php

namespace App\Console\Commands;

use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\SpecialiteMedicale;
use Illuminate\Console\Command;

/**
 * P6.8a — Rattache l'existant au vocabulaire des spécialités (CDC_09 §8).
 *
 * ═══ DEUX CHEMINS, ET LE SECOND N'EST PAS UNE DEVINETTE ═══
 *
 * Un SERVICE porte déjà le code : `services_etablissement.specialite` vaut `orl`, `cardiologie`…
 * Le rattachement est une simple résolution — c'est tout l'intérêt d'avoir ADOPTÉ ces codes plutôt
 * que d'en inventer.
 *
 * Un PRATICIEN, lui, ne porte pas de code : `medecins.specialite` est un libellé libre, et le
 * seeder y a même recopié le NOM DU SERVICE (« Maternité », « Urgences »). Le rapprocher par
 * ressemblance de libellé produirait des rattachements faux avec l'assurance d'une machine. On
 * passe donc par le lien STRUCTUREL qui existe déjà : `medecins.service_id` → le service → son
 * terme. Un praticien sans service, ou dont le service n'est pas rattaché, reste NULL.
 *
 * ═══ CE QU'ELLE NE FAIT PAS ═══
 *
 * Elle ne réécrit AUCUN libellé. « Maternité » reste « Maternité » dans l'annuaire public : c'est ce
 * que l'établissement affiche, et le remplacer d'office par « Gynécologie-obstétrique » changerait
 * ce que le patient lit sans que personne ne l'ait décidé (leçon de P6.7b — un serveur qui réécrit
 * une déclaration humaine se trompe avec autorité). L'écart est SIGNALÉ à l'écran du référentiel,
 * là où quelqu'un peut le corriger en connaissance de cause.
 *
 * IDEMPOTENTE : une ligne déjà rattachée n'est pas retouchée, un rejeu n'écrit rien.
 *
 *   XDEBUG_MODE=off php artisan masante:specialites:backfill --dry-run
 */
class BackfillSpecialites extends Command
{
    protected $signature = 'masante:specialites:backfill
                            {--dry-run : Compte les lignes concernées sans rien écrire}
                            {--pays=CI : Code pays du vocabulaire à appliquer}';

    protected $description = 'Rattache services et praticiens au vocabulaire des spécialités (CDC_09 §8)';

    public function handle(): int
    {
        $pays   = strtoupper((string) $this->option('pays'));
        $simule = (bool) $this->option('dry-run');

        /** @var array<string, int> $termes */
        $termes = SpecialiteMedicale::where('pays_code', $pays)->pluck('id', 'code')->all();

        if ($termes === []) {
            $this->error("Aucun terme au vocabulaire pour le pays {$pays} — lancez d'abord "
                .'`php artisan db:seed --class=SpecialiteMedicaleSeeder`.');

            return self::FAILURE;
        }

        $services   = $this->rattacherServices($termes, $simule);
        $praticiens = $this->rattacherPraticiens($termes, $simule);

        $this->newLine();

        if ($simule) {
            $this->info("{$services} service(s) et {$praticiens} praticien(s) seraient rattachés.");

            return self::SUCCESS;
        }

        $this->info("{$services} service(s) et {$praticiens} praticien(s) rattachés au vocabulaire.");

        // Un code de service absent du vocabulaire n'est pas une erreur de cette commande : c'est
        // une valeur que le formulaire acceptait avant P6.8a. On la nomme plutôt que de la taire —
        // sinon le service resterait invisible au référentiel sans que personne ne sache pourquoi.
        $this->signalerLesInconnus($termes);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $termes
     */
    private function rattacherServices(array $termes, bool $simule): int
    {
        $rattaches = 0;

        ServiceEtablissement::whereNull('specialite_id')
            ->orderBy('id')
            ->chunkById(200, function ($lot) use ($termes, $simule, &$rattaches) {
                foreach ($lot as $service) {
                    $id = $termes[$service->specialite] ?? null;

                    if ($id === null) {
                        continue;
                    }

                    $rattaches++;

                    if (! $simule) {
                        $service->forceFill(['specialite_id' => $id])->save();
                    }
                }
            });

        return $rattaches;
    }

    /**
     * @param  array<string, int>  $termes
     */
    private function rattacherPraticiens(array $termes, bool $simule): int
    {
        $rattaches = 0;

        Medecin::whereNull('specialite_id')
            ->whereNotNull('service_id')
            ->with('service:id,specialite,specialite_id')
            ->orderBy('id')
            ->chunkById(200, function ($lot) use ($termes, $simule, &$rattaches) {
                foreach ($lot as $praticien) {
                    // EN SIMULATION, le service n'a pas encore été écrit : lire son
                    // `specialite_id` renverrait NULL et la commande annoncerait « 0 praticien »
                    // avant d'en rattacher vingt-huit. Un aperçu qui sous-estime ce qu'il va faire
                    // n'aide pas à décider — on résout donc comme le passage réel le fera.
                    // Défaut trouvé au G2, pas par les tests.
                    $id = $praticien->service?->specialite_id
                        ?? ($termes[$praticien->service?->specialite] ?? null);

                    if ($id === null) {
                        continue;
                    }

                    $rattaches++;

                    if (! $simule) {
                        $praticien->forceFill(['specialite_id' => $id])->save();
                    }
                }
            });

        return $rattaches;
    }

    /**
     * @param  array<string, int>  $termes
     */
    private function signalerLesInconnus(array $termes): void
    {
        $inconnus = ServiceEtablissement::whereNull('specialite_id')
            ->distinct()
            ->pluck('specialite')
            ->reject(fn (?string $code): bool => $code === null || isset($termes[$code]))
            ->values();

        if ($inconnus->isEmpty()) {
            return;
        }

        $this->warn('Codes de service absents du vocabulaire (non rattachés) : '.$inconnus->implode(', '));
        $this->line('  Ajoutez-les au vocabulaire, ou corrigez le service depuis le portail.');
    }
}
