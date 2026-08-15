<?php

namespace App\Console\Commands;

use App\Models\MembreFamille;
use App\Services\ServiceNotification;
use App\Services\Vaccin\ServiceCalendrierVaccinal;
use App\Support\ReglesCalendrierVaccinal;
use App\Support\TypeNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * P6.8b — Prévient quand une échéance du calendrier vaccinal national est atteinte (CDC_09 §8).
 *
 * ═══ CE QU'ELLE FAIT, ET SURTOUT CE QU'ELLE N'ÉCRIT PAS ═══
 *
 * Elle émet une notification (canal P7-D1). Elle n'écrit **rien dans le carnet** : ni ligne de
 * `vaccinations`, ni ligne de `rappels`. C'est la décision W3 du propriétaire — générer des lignes
 * serait un quatrième chemin d'écriture dans une table du carnet, avec la question du rejeu et de
 * la suppression par le patient, alors que la notification obtient le même résultat sans ouvrir
 * cette porte. `rappels.type = 'vaccin'` reste donc saisi à la main, et c'est dit comme limite.
 *
 * ═══ IDEMPOTENTE PAR CONSTRUCTION, SANS TABLE DE SUIVI ═══
 *
 * Une échéance devient exigible **le jour exact** où l'enfant atteint `age_jours_du` : c'est une
 * fonction de sa date de naissance, donc un fait calculable, pas un état à suivre. On ne notifie
 * donc que les deux jours qui comptent — celui où la dose devient due, et celui où le délai de
 * grâce publié s'achève. *Une table de marqueurs aurait stocké ce que l'arithmétique sait déjà,
 * et il aurait fallu la purger.*
 *
 * Un second passage le MÊME jour ne renotifie pas : une garde relit les notifications déjà émises
 * du jour pour ce membre. Sans elle, un rejeu manuel de la commande aurait doublé l'envoi.
 *
 * ═══ AUCUN NOM DE VACCIN NE SORT ═══
 *
 * La règle inviolable de D1 mord ici : le message dit combien de vaccinations sont dues, jamais
 * lesquelles ({@see App\Services\Notifications\ServiceNotification::echeanceVaccinale}).
 *
 *   XDEBUG_MODE=off php artisan masante:vaccins:echeances --dry-run
 */
class NotifierEcheancesVaccinales extends Command
{
    protected $signature = 'masante:vaccins:echeances
                            {--dry-run : Affiche les envois sans notifier}
                            {--date= : Date de référence (AAAA-MM-JJ), pour rejouer un jour donné}';

    protected $description = 'Notifie les échéances du calendrier vaccinal national (CDC_09 §8)';

    public function handle(
        ServiceCalendrierVaccinal $calendrier,
        ServiceNotification $notifications,
    ): int {
        if (! $calendrier->estEnVigueur()) {
            // ÉCHEC BRUYANT, jamais un silence : une commande planifiée qui ne dit rien laisserait
            // croire qu'aucune échéance n'est due, alors que le calendrier n'est pas publié.
            $this->error('Le calendrier vaccinal national n\'a aucune version en vigueur : '
                .'aucune échéance ne peut être établie (CDC_09 §10).');

            return self::FAILURE;
        }

        $jour   = CarbonImmutable::parse($this->option('date') ?? CarbonImmutable::now())->startOfDay();
        $simule = (bool) $this->option('dry-run');
        $envois = 0;

        MembreFamille::query()
            ->whereNotNull('date_naissance')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(200, function ($lot) use ($calendrier, $notifications, $jour, $simule, &$envois) {
                foreach ($lot as $membre) {
                    $envois += $this->traiter($membre, $calendrier, $notifications, $jour, $simule);
                }
            });

        $this->newLine();
        $this->info($simule
            ? "{$envois} notification(s) seraient émises pour le {$jour->toDateString()}."
            : "{$envois} notification(s) émise(s) pour le {$jour->toDateString()}.");

        return self::SUCCESS;
    }

    /** Traite un membre ; renvoie le nombre de notifications émises pour lui. */
    private function traiter(
        MembreFamille $membre,
        ServiceCalendrierVaccinal $calendrier,
        ServiceNotification $notifications,
        CarbonImmutable $jour,
        bool $simule,
    ): int {
        $age = ReglesCalendrierVaccinal::ageEnJours($membre->date_naissance, $jour);

        if ($age === null) {
            return 0;
        }

        $dues = 0;
        $tard = 0;

        foreach ($calendrier->pour($membre, $jour)['echeances'] as $echeance) {
            // Une dose déjà faite, ou hors fenêtre de rattrapage, n'appelle aucun rappel : la
            // première est honorée, la seconde n'est plus proposée par le calendrier.
            if (! in_array($echeance['statut'], [
                ReglesCalendrierVaccinal::A_FAIRE,
                ReglesCalendrierVaccinal::EN_RETARD,
            ], true)) {
                continue;
            }

            $du = (int) $echeance['age_jours_du'];

            // LE JOUR EXACT où elle devient due.
            if ($age === $du) {
                $dues++;

                continue;
            }

            // LE JOUR EXACT où le délai de grâce publié s'achève. On relit la tolérance depuis
            // l'échéance publiée plutôt que de la supposer : elle est une DONNÉE du référentiel,
            // et deux doses du même vaccin peuvent ne pas partager la même.
            $tolerance = (int) ($calendrier->echeancePubliee(
                (string) $echeance['vaccin_code'],
                (int) $echeance['numero_dose'],
            )['tolerance_jours'] ?? 0);

            if ($age === $du + $tolerance + 1) {
                $tard++;
            }
        }

        return $this->emettre($membre, $notifications, $jour, $simule, $dues, false)
             + $this->emettre($membre, $notifications, $jour, $simule, $tard, true);
    }

    private function emettre(
        MembreFamille $membre,
        ServiceNotification $notifications,
        CarbonImmutable $jour,
        bool $simule,
        int $nombre,
        bool $enRetard,
    ): int {
        if ($nombre === 0 || $this->dejaNotifie($membre, $jour, $enRetard)) {
            return 0;
        }

        $this->line(sprintf(
            '  %s — %d échéance(s) %s',
            trim($membre->prenom.' '.$membre->nom),
            $nombre,
            $enRetard ? 'en retard' : 'à faire',
        ));

        if (! $simule) {
            $notifications->echeanceVaccinale($membre, $nombre, $enRetard);
        }

        return 1;
    }

    /**
     * Ce membre a-t-il déjà reçu ce palier aujourd'hui ?
     *
     * La garde du rejeu manuel. Le déclenchement au jour exact suffit pour un passage quotidien ;
     * il ne suffit pas si quelqu'un relance la commande à la main dans l'après-midi.
     */
    private function dejaNotifie(MembreFamille $membre, CarbonImmutable $jour, bool $enRetard): bool
    {
        return DatabaseNotification::query()
            ->where('type', TypeNotification::ECHEANCE_VACCINALE->value)
            ->whereDate('created_at', $jour->toDateString())
            ->where('data->membre_id', $membre->id)
            ->where('data->en_retard', $enRetard)
            ->exists();
    }
}
