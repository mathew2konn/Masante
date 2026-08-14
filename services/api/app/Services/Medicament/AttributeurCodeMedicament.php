<?php

namespace App\Services\Medicament;

use App\Models\Medicament;
use Illuminate\Support\Facades\DB;

/**
 * Attribue son code national à un médicament (CDC_09 §6.2).
 *
 * IDEMPOTENT : un produit qui en a déjà un le conserve, et la séquence n'est pas consommée — c'est
 * ce qui rend la commande de backfill rejouable et l'appel à la création inoffensif.
 *
 * PAS DE JOURNAL DE NON-RÉUTILISATION, comme pour `ETS` (P6.4a) et `PRO` (P6.5a). §3.2 exige qu'un
 * NIS ne soit jamais réattribué parce qu'il désigne une PERSONNE dans son dossier de santé ; le
 * corpus n'exige rien de tel au §6. Une symétrie décorative coûterait une table à maintenir pour
 * une garantie que personne ne réclame.
 *
 * La question mérite pourtant d'être posée le jour où §6.5 propagera les retraits : réattribuer le
 * code d'un produit retiré ferait pointer une alerte ancienne vers un autre médicament. Elle n'est
 * pas tranchée ici, et surtout elle n'est pas préemptée par une table vide créée « au cas où ».
 */
final class AttributeurCodeMedicament
{
    public function __construct(private readonly GenerateurCodeMedicament $generateur) {}

    /** Renvoie le code du médicament, en l'attribuant s'il n'en a pas. */
    public function attribuer(Medicament $medicament): string
    {
        if ($medicament->code !== null) {
            return $medicament->code;
        }

        return DB::transaction(function () use ($medicament): string {
            // Relecture sous verrou : deux requêtes simultanées sur la même fiche ne peuvent pas
            // lui attribuer deux codes et en consommer deux dans la séquence.
            $verrouille = Medicament::query()
                ->whereKey($medicament->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouille->code !== null) {
                $medicament->setAttribute('code', $verrouille->code);

                return $verrouille->code;
            }

            $paysCode = $verrouille->pays_code ?? config('referentiels.pays_defaut');
            $code     = $this->generateur->suivant($paysCode);

            // `forceFill` : le code national est HORS `$fillable` — un client ne choisit pas
            // l'identifiant national d'un produit, il le reçoit. Seul ce service l'écrit.
            $verrouille->forceFill(['code' => $code, 'pays_code' => $paysCode])->save();

            $medicament->setAttribute('code', $code);
            $medicament->setAttribute('pays_code', $paysCode);

            return $code;
        }, 3);
    }
}
