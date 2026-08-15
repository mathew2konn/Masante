<?php

namespace App\Console\Commands;

use App\Models\AlerteEpidemique;
use App\Models\Maladie;
use App\Services\Maladie\AttributeurCodeMaladie;
use App\Services\Maladie\ServiceMaladies;
use Illuminate\Console\Command;

/**
 * P6.8c — Attribution du code national aux maladies, et rattachement des alertes existantes.
 *
 * IDEMPOTENTE : la garantie vient de `AttributeurCodeMaladie`, pas de cette commande. Une entrée qui
 * a déjà un code le conserve, et la séquence n'est pas consommée — un rejeu ne crée aucun trou.
 *
 * ORDRE STABLE par `id` croissant, comme en P6.1, P6.4a, P6.5a, P6.6a, P6.7a et P6.8b : le backfill
 * est reproductible d'une base à l'autre.
 *
 * ═══ LE RATTACHEMENT DES ALERTES SE FAIT PAR ÉGALITÉ EXACTE, JAMAIS PAR RESSEMBLANCE ═══
 *
 * `alertes_epidemiques.maladie` porte un texte libre. On ne le rapproche du référentiel que si les
 * deux chaînes sont IDENTIQUES une fois la casse et les accents normalisés — c'est une résolution
 * d'IDENTITÉ, pas un rapprochement flou. Mesurer une distance entre « Choléra » et « Cholécystite »
 * pour décider laquelle un agent voulait dire serait deviner une maladie, et le G1 l'exclut
 * explicitement (CDC_00 §4). Ce qui n'est pas rapproché est COMPTÉ et laissé tel quel.
 *
 * ═══ CE QU'ELLE NE FAIT PAS ═══
 *
 * Elle ne touche pas aux ANTÉCÉDENTS. `description` est chiffrée, elle appartient au patient, et
 * y deviner une maladie serait un diagnostic posé par une machine. Un antécédent se rattache quand
 * un humain le déclare, jamais rétroactivement — *leur inventer un code serait un mensonge
 * d'archive* (précédent L2).
 *
 *   XDEBUG_MODE=off php artisan masante:maladies:backfill --dry-run
 */
class BackfillMaladies extends Command
{
    protected $signature = 'masante:maladies:backfill
                            {--dry-run : Compte les entrées concernées sans rien écrire}';

    protected $description = 'Attribue un code national aux maladies et rattache les alertes '
        .'existantes par égalité exacte de libellé (CDC_09 §8)';

    public function handle(AttributeurCodeMaladie $attributeur): int
    {
        $simule   = (bool) $this->option('dry-run');
        $sansCode = Maladie::whereNull('code')->count();

        // L'APERÇU ANNONCE EXACTEMENT CE QUE FERA LE PASSAGE RÉEL. Le G2 de P6.8a a trouvé
        // l'inverse : un `--dry-run` annonçait « 0 praticien » avant que le passage réel n'en
        // rattache 28. *Un aperçu qui sous-estime ce qu'il va faire n'aide pas à décider.*
        $rattachables = $this->alertesRattachables();

        if ($sansCode === 0 && $rattachables === []) {
            $this->info('Toutes les maladies ont un code national et aucune alerte n\'est '
                .'rattachable — rien à faire.');

            return self::SUCCESS;
        }

        if ($simule) {
            $this->info("{$sansCode} maladie(s) recevraient un code national.");
            $this->info(count($rattachables).' alerte(s) seraient rattachées par égalité exacte '
                .'de libellé.');
            $this->ligneDesEcarts();

            return self::SUCCESS;
        }

        $this->info('Attribution en cours…');
        $codes = 0;

        Maladie::orderBy('id')->chunkById(100, function ($lot) use ($attributeur, &$codes) {
            foreach ($lot as $maladie) {
                if ($maladie->code === null) {
                    $code = $attributeur->attribuer($maladie);
                    $codes++;
                    $this->line("  {$code}  ←  {$maladie->libelle}");
                }
            }
        });

        $rattachees = $this->rattacherLesAlertes();

        $this->newLine();
        $this->info("{$codes} code(s) national/nationaux attribué(s).");
        $this->info("{$rattachees} alerte(s) rattachée(s) au référentiel.");
        $this->ligneDesEcarts();

        return self::SUCCESS;
    }

    /**
     * Les alertes qu'un rattachement par égalité exacte atteindrait.
     *
     * @return array<int, int> identifiants d'alerte → identifiant de maladie
     */
    private function alertesRattachables(): array
    {
        $parLibelle = Maladie::query()
            ->get(['id', 'libelle'])
            ->mapWithKeys(fn (Maladie $m): array => [
                ServiceMaladies::normaliser($m->libelle) => $m->id,
            ])
            ->all();

        $paires = [];

        foreach (AlerteEpidemique::whereNull('maladie_id')->get(['id', 'maladie']) as $alerte) {
            $cle = ServiceMaladies::normaliser((string) $alerte->maladie);

            if (isset($parLibelle[$cle])) {
                $paires[$alerte->id] = $parLibelle[$cle];
            }
        }

        return $paires;
    }

    private function rattacherLesAlertes(): int
    {
        $rattachees = 0;

        foreach ($this->alertesRattachables() as $alerteId => $maladieId) {
            $maladie = Maladie::find($maladieId);

            if ($maladie === null || $maladie->code === null) {
                continue;   // Sans code national, le rattachement n'apporterait rien d'exploitable.
            }

            AlerteEpidemique::whereKey($alerteId)->update([
                'maladie_id'   => $maladie->id,
                'maladie_code' => $maladie->code,
            ]);

            $rattachees++;
        }

        return $rattachees;
    }

    /**
     * Le témoin de l'écart (décision E4) : ce qui reste hors référentiel est COMPTÉ, jamais tu — et
     * jamais rapproché de force.
     */
    private function ligneDesEcarts(): void
    {
        $hors = AlerteEpidemique::horsReferentiel()->count();

        if ($hors > 0) {
            $this->warn("{$hors} alerte(s) ne désignent aucune entrée du référentiel : leur libellé "
                .'ne correspond à aucun libellé national à la lettre près. Elles restent publiées '
                .'telles quelles — aucun rapprochement approximatif n\'est fait.');
        }
    }
}
