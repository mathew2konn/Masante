<?php

namespace Database\Seeders;

use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Support\RegistreReferentiels;
use Illuminate\Database\Seeder;

/**
 * P6.3 — Inscrit au registre les référentiels de la liste blanche (CDC_09 §10).
 *
 * IDEMPOTENT, et c'est essentiel : rejouer ce seeder ne crée aucune ligne et n'ajoute AUCUN maillon
 * à la chaîne d'audit. Un seeder qui journaliserait à chaque exécution polluerait la trace qu'il est
 * censé rendre lisible.
 *
 * IL N'ENREGISTRE QUE LE REGISTRE, PAS DE VERSION. Aucun référentiel n'est publié d'office : la
 * première mise en vigueur passe par le cycle §10 (une proposition, une décision par quelqu'un
 * d'autre). Publier depuis un seeder reviendrait à contourner la gouvernance dès le premier jour —
 * et il n'y aurait alors personne à qui rattacher la décision.
 */
class ReferentielRegistreSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(ServiceGouvernanceReferentiel::class);

        foreach (RegistreReferentiels::codes() as $code) {
            $referentiel = $service->enregistrer($code, config('referentiels.pays_defaut'));

            $this->command?->info("Référentiel « {$referentiel->code} » au registre "
                .'(version publiée : '.($referentiel->version_publiee_numero ?? 'aucune').').');
        }
    }
}
