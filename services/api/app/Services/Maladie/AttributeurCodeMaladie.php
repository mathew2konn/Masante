<?php

namespace App\Services\Maladie;

use App\Models\Maladie;
use Illuminate\Support\Facades\DB;

/**
 * Attribue son code national à une maladie (CDC_09 §8).
 *
 * IDEMPOTENT : une entrée qui en a déjà un le conserve, et la séquence n'est pas consommée — c'est
 * ce qui rend la commande de backfill rejouable et l'appel à la création inoffensif.
 *
 * Pas de journal de non-réutilisation, comme pour `ETS`, `PRO`, `MED`, `ANA` et `VAC` : §3.2 l'exige
 * pour un NIS parce qu'il désigne une PERSONNE dans son dossier de santé. Le corpus n'exige rien de
 * tel au §8, et une symétrie décorative coûterait une table à maintenir pour une garantie que
 * personne ne réclame.
 */
final class AttributeurCodeMaladie
{
    public function __construct(private readonly GenerateurCodeMaladie $generateur) {}

    public function attribuer(Maladie $maladie): string
    {
        if ($maladie->code !== null) {
            return $maladie->code;
        }

        return DB::transaction(function () use ($maladie): string {
            // Relecture sous verrou : deux requêtes simultanées sur la même fiche ne peuvent pas
            // lui attribuer deux codes et en consommer deux dans la séquence.
            $verrouille = Maladie::query()->whereKey($maladie->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouille->code !== null) {
                $maladie->setAttribute('code', $verrouille->code);

                return $verrouille->code;
            }

            $code = $this->generateur->suivant();

            // `forceFill` : le code national est HORS `$fillable`. Seul ce service l'écrit.
            $verrouille->forceFill(['code' => $code])->save();

            $maladie->setAttribute('code', $code);

            return $code;
        }, 3);
    }
}
