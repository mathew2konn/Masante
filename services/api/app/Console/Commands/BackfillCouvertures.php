<?php

namespace App\Console\Commands;

use App\Models\CouvertureMembre;
use App\Models\MembreFamille;
use App\Models\OrganismeAssurance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P6.8d — Transposition des colonnes `cmu_*` vers `couvertures_membre` (CDC_09 §8).
 *
 * ═══ CETTE COMMANDE EST UNE ÉTAPE DE DÉPLOIEMENT ═══
 *
 * Tant qu'elle n'a pas tourné, un membre dont la colonne dit « actif » répond `non_inscrit` : les
 * accesseurs de `MembreFamille` lisent la couverture et **ne se replient jamais sur la colonne** —
 * un repli ressusciterait une valeur périmée le jour où un citoyen supprime sa couverture, et
 * rétablirait les deux vérités que ce module supprime. Même nature que la publication de la v1 en
 * L1+L2 : une bascule se fait, elle ne se devine pas.
 *
 * ═══ ELLE NE PASSE PAS PAR `ServiceCouvertures`, ET C'EST DÉLIBÉRÉ ═══
 *
 * Le service exige que l'organisme figure dans la **version publiée** du référentiel. C'est la bonne
 * règle pour une DÉCLARATION faite par un citoyen — on ne se rattache pas à ce que personne n'a mis
 * en vigueur. Ce n'en est pas une ici : **rien n'est déclaré, une déclaration existante est
 * déplacée**. Appliquer la règle du chemin de déclaration rendrait les données existantes invisibles
 * jusqu'à la première publication, alors que le citoyen, lui, avait déjà saisi sa CMU.
 *
 * ═══ LES COLONNES D'ORIGINE NE SONT PAS EFFACÉES ═══
 *
 * ADR-024 : une migration destructive perdrait de l'information réelle pour un gain nul (précédent
 * P6.4d-K2). Elles restent en base, plus personne ne les lit ni ne les écrit. C'est ce qui rend cette
 * commande rejouable sans risque, et une erreur de transposition rattrapable.
 *
 * IDEMPOTENTE : un membre qui a déjà une couverture CNAM n'est pas retouché.
 *
 *   XDEBUG_MODE=off php artisan masante:couvertures:backfill --dry-run
 */
class BackfillCouvertures extends Command
{
    protected $signature = 'masante:couvertures:backfill
                            {--dry-run : Compte les membres concernés sans rien écrire}';

    protected $description = 'Transpose les colonnes cmu_* des membres vers les couvertures santé '
        .'rattachées au référentiel des organismes (CDC_09 §8)';

    public function handle(): int
    {
        $simule = (bool) $this->option('dry-run');
        $cnam   = $this->organismeCnam();

        if ($cnam === null) {
            $this->error('Aucun organisme de type « cnam » dans le registre : lancez d\'abord '
                .'`db:seed --class=OrganismeAssuranceSeeder`. Rien n\'a été écrit.');

            return self::FAILURE;
        }

        $aTransposer = $this->membresATransposer();
        $ambigus     = $this->membresAmbigus();

        if ($aTransposer->isEmpty()) {
            $this->info('Aucune colonne `cmu_*` à transposer — rien à faire.');
            $this->ligneDesAmbigus($ambigus);

            return self::SUCCESS;
        }

        if ($simule) {
            // L'APERÇU ANNONCE EXACTEMENT CE QUE FERA LE PASSAGE RÉEL, y compris les approximations
            // de date (leçon du G2 de P6.8a).
            $this->info($aTransposer->count().' membre(s) recevraient une couverture « '
                .$cnam->nom_court.' ».');
            $this->ligneDesApproximations($aTransposer);
            $this->ligneDesAmbigus($ambigus);

            return self::SUCCESS;
        }

        $crees = 0;

        foreach ($aTransposer as $membre) {
            DB::transaction(function () use ($membre, $cnam, &$crees): void {
                $couverture = new CouvertureMembre([
                    'organisme_assurance_id' => $cnam->id,
                    // Déchiffré par le cast à la lecture, rechiffré par le cast à l'écriture : le
                    // numéro ne transite jamais en clair par une colonne intermédiaire.
                    'numero_assure'          => $membre->getRawOriginal('cmu_numero') === null
                        ? null
                        : $membre->getAttribute('cmu_numero'),
                    'date_fin'               => $this->dateFinPour($membre),
                ]);
                $couverture->membre_id  = $membre->id;
                $couverture->provenance = 'declare';
                $couverture->save();

                $crees++;
            });

            $this->line("  couverture ← membre #{$membre->id} ({$membre->prenom} {$membre->nom})");
        }

        $this->newLine();
        $this->info("{$crees} couverture(s) créée(s), rattachée(s) à « {$cnam->nom_court} ».");
        $this->ligneDesApproximations($aTransposer);
        $this->ligneDesAmbigus($ambigus);
        $this->info('Les colonnes `cmu_*` sont conservées telles quelles : plus personne ne les lit '
            .'ni ne les écrit (ADR-024).');

        return self::SUCCESS;
    }

    private function organismeCnam(): ?OrganismeAssurance
    {
        // PAR LE TYPE, jamais par le nom : « CMU » est le régime, la CNAM est l'organisme qui le
        // gère (CDC_06 §8.1). Chercher « CMU » dans un libellé rendrait la bascule dépendante d'une
        // chaîne de caractères — exactement ce que ce module supprime.
        return OrganismeAssurance::query()
            ->where('pays_code', strtoupper((string) config('referentiels.pays_defaut', 'CI')))
            ->where('type', 'cnam')
            ->orderBy('id')
            ->first();
    }

    /**
     * Les membres dont la déclaration héritée dit quelque chose, et qui n'ont pas encore de
     * couverture CNAM.
     *
     * @return \Illuminate\Support\Collection<int, MembreFamille>
     */
    private function membresATransposer(): \Illuminate\Support\Collection
    {
        return MembreFamille::query()
            ->whereIn('cmu_statut', ['actif', 'expire'])
            ->whereDoesntHave('couvertures.organisme', fn ($q) => $q->where('type', 'cnam'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Les membres qui ont saisi un numéro puis déclaré « non inscrit ».
     *
     * ON NE DEVINE PAS : la déclaration dit « pas de couverture », le numéro dit le contraire. Leur
     * en fabriquer une trancherait à la place de l'assuré. Ils sont COMPTÉS et laissés tels quels —
     * même principe que les alertes non rattachées de P6.8c.
     *
     * @return \Illuminate\Support\Collection<int, MembreFamille>
     */
    private function membresAmbigus(): \Illuminate\Support\Collection
    {
        return MembreFamille::query()
            ->where('cmu_statut', 'non_inscrit')
            ->whereNotNull('cmu_numero')
            ->orderBy('id')
            ->get();
    }

    /**
     * ═══ L'APPROXIMATION EST ASSUMÉE ET DITE ═══
     *
     * `expire` sans date de validité est une déclaration CONTRADICTOIRE : l'assuré affirme que sa
     * couverture ne vaut plus, sans dire depuis quand. Sous le nouveau modèle l'expiration est une
     * date, et nous ne l'avons pas.
     *
     * Laisser la date vide ferait calculer `active` — le système CONTREDIRAIT l'assuré. Inscrire la
     * veille du backfill dit « elle ne vaut plus », ce qui est vrai, au prix d'une date de fin qui
     * ne l'est pas. C'est la seule des deux formulations qui ne réactive pas une couverture que
     * l'intéressé a lui-même déclarée expirée — et l'écran laisse la corriger.
     */
    private function dateFinPour(MembreFamille $membre): ?string
    {
        $validite = $membre->getRawOriginal('cmu_validite');

        if ($validite !== null) {
            return (string) $validite;
        }

        return $membre->getRawOriginal('cmu_statut') === 'expire'
            ? now()->subDay()->toDateString()
            : null;
    }

    /** @param  \Illuminate\Support\Collection<int, MembreFamille>  $membres */
    private function ligneDesApproximations(\Illuminate\Support\Collection $membres): void
    {
        $approximees = $membres->filter(
            fn (MembreFamille $m): bool => $m->getRawOriginal('cmu_statut') === 'expire'
                && $m->getRawOriginal('cmu_validite') === null,
        )->count();

        if ($approximees > 0) {
            $this->warn("{$approximees} membre(s) déclaraient « expirée » SANS date de validité : "
                .'leur date de fin est fixée à la veille de ce passage. Nous savons que la '
                .'couverture ne vaut plus, nous ne savons pas depuis quand — cette date est une '
                .'approximation, corrigeable depuis l\'écran des couvertures.');
        }
    }

    /** @param  \Illuminate\Support\Collection<int, MembreFamille>  $membres */
    private function ligneDesAmbigus(\Illuminate\Support\Collection $membres): void
    {
        if ($membres->isNotEmpty()) {
            $this->warn($membres->count().' membre(s) portent un numéro CMU tout en se déclarant '
                .'« non inscrit ». Aucune couverture ne leur est créée : la déclaration et le '
                .'numéro se contredisent, et trancher reviendrait à décider à leur place.');
        }
    }
}
