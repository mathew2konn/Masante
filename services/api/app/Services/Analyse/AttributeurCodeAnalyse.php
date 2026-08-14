<?php

namespace App\Services\Analyse;

use App\Models\Analyse;
use Illuminate\Support\Facades\DB;

/**
 * Attribue son code national à une analyse (CDC_09 §7.3).
 *
 * IDEMPOTENT : une entrée qui en a déjà un le conserve, et la séquence n'est pas consommée — c'est
 * ce qui rend la commande de backfill rejouable et l'appel à la création inoffensif.
 *
 * Pas de journal de non-réutilisation, comme pour `ETS`, `PRO` et `MED` : §3.2 l'exige pour un NIS
 * parce qu'il désigne une PERSONNE dans son dossier de santé. Le corpus n'exige rien de tel au §7.
 */
final class AttributeurCodeAnalyse
{
    public function __construct(private readonly GenerateurCodeAnalyse $generateur) {}

    public function attribuer(Analyse $analyse): string
    {
        if ($analyse->code !== null) {
            return $analyse->code;
        }

        return DB::transaction(function () use ($analyse): string {
            // Relecture sous verrou : deux requêtes simultanées sur la même entrée ne peuvent pas
            // lui attribuer deux codes et en consommer deux dans la séquence.
            $verrouillee = Analyse::query()->whereKey($analyse->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouillee->code !== null) {
                $analyse->setAttribute('code', $verrouillee->code);

                return $verrouillee->code;
            }

            $paysCode = $verrouillee->pays_code ?? config('referentiels.pays_defaut');
            $code     = $this->generateur->suivant($paysCode);

            // `forceFill` : le code national est hors `$fillable`. Seul ce service l'écrit.
            $verrouillee->forceFill(['code' => $code, 'pays_code' => $paysCode])->save();

            $analyse->setAttribute('code', $code);
            $analyse->setAttribute('pays_code', $paysCode);

            return $code;
        }, 3);
    }
}
