<?php

namespace App\Services\Etablissement;

use App\Models\StructureSanitaire;
use Illuminate\Support\Facades\DB;

/**
 * Attribue son identifiant national à un établissement (CDC_09 §4.3).
 *
 * IDEMPOTENT : un établissement qui en a déjà un le conserve, et la séquence n'est pas consommée.
 * C'est ce qui rend la commande de backfill rejouable et l'appel à la création inoffensif.
 *
 * PAS DE JOURNAL DE NON-RÉUTILISATION, contrairement au NIS — et c'est une différence assumée.
 * §3.2 impose explicitement qu'un NIS ne soit jamais réattribué, parce qu'il désigne une personne
 * et que le confondre avec une autre serait une faute grave. Le CDC n'exige rien de tel pour un
 * établissement. Ajouter un journal ici serait de la symétrie décorative : on aurait une table
 * de plus à maintenir pour une garantie que personne ne demande.
 */
final class AttributeurIdentifiantEtablissement
{
    public function __construct(private readonly GenerateurIdentifiantEtablissement $generateur) {}

    /** Renvoie l'identifiant de l'établissement, en l'attribuant s'il n'en a pas. */
    public function attribuer(StructureSanitaire $etablissement): string
    {
        if ($etablissement->identifiant_national !== null) {
            return $etablissement->identifiant_national;
        }

        return DB::transaction(function () use ($etablissement): string {
            // Relecture sous verrou : deux requêtes simultanées sur le même établissement ne
            // peuvent pas lui attribuer deux identifiants et en consommer deux dans la séquence.
            $verrouille = StructureSanitaire::query()
                ->whereKey($etablissement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouille->identifiant_national !== null) {
                $etablissement->setAttribute('identifiant_national', $verrouille->identifiant_national);

                return $verrouille->identifiant_national;
            }

            $paysCode = $verrouille->pays_code ?? config('referentiels.pays_defaut');
            $identifiant = $this->generateur->suivant($paysCode);

            $verrouille->forceFill([
                'identifiant_national' => $identifiant,
                'pays_code'            => $paysCode,
            ])->save();

            $etablissement->setAttribute('identifiant_national', $identifiant);
            $etablissement->setAttribute('pays_code', $paysCode);

            return $identifiant;
        }, 3);
    }
}
