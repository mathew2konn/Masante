<?php

namespace App\Services\Vaccin;

use App\Models\Vaccin;
use Illuminate\Support\Facades\DB;

/**
 * Attribue son code national à un vaccin (CDC_09 §8).
 *
 * IDEMPOTENT : une entrée qui en a déjà un le conserve, et la séquence n'est pas consommée — c'est
 * ce qui rend la commande de backfill rejouable et l'appel à la création inoffensif.
 *
 * Pas de journal de non-réutilisation, comme pour `ETS`, `PRO`, `MED` et `ANA` : §3.2 l'exige pour
 * un NIS parce qu'il désigne une PERSONNE dans son dossier de santé. Le corpus n'exige rien de tel
 * au §8, et une symétrie décorative coûterait une table à maintenir pour une garantie que personne
 * ne réclame.
 */
final class AttributeurCodeVaccin
{
    public function __construct(private readonly GenerateurCodeVaccin $generateur) {}

    public function attribuer(Vaccin $vaccin): string
    {
        if ($vaccin->code !== null) {
            return $vaccin->code;
        }

        return DB::transaction(function () use ($vaccin): string {
            // Relecture sous verrou : deux requêtes simultanées sur la même fiche ne peuvent pas
            // lui attribuer deux codes et en consommer deux dans la séquence.
            $verrouille = Vaccin::query()->whereKey($vaccin->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouille->code !== null) {
                $vaccin->setAttribute('code', $verrouille->code);

                return $verrouille->code;
            }

            $paysCode = $verrouille->pays_code ?? config('referentiels.pays_defaut');
            $code     = $this->generateur->suivant($paysCode);

            // `forceFill` : le code national est HORS `$fillable`. Seul ce service l'écrit.
            $verrouille->forceFill(['code' => $code, 'pays_code' => $paysCode])->save();

            $vaccin->setAttribute('code', $code);
            $vaccin->setAttribute('pays_code', $paysCode);

            return $code;
        }, 3);
    }
}
