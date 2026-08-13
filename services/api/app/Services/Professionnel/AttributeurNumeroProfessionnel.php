<?php

namespace App\Services\Professionnel;

use App\Models\Medecin;
use Illuminate\Support\Facades\DB;

/**
 * Attribue son numéro national à un professionnel de santé (CDC_09 §5.2).
 *
 * IDEMPOTENT : une fiche qui en a déjà un le conserve, et la séquence n'est pas consommée. C'est
 * ce qui rend la commande de backfill rejouable et l'appel à la création inoffensif.
 *
 * PAS DE JOURNAL DE NON-RÉUTILISATION, comme pour les établissements et contrairement au NIS.
 * §3.2 impose explicitement qu'un NIS ne soit jamais réattribué parce qu'il désigne une PERSONNE
 * dans son dossier de santé, et que la confondre avec une autre serait une faute grave. Le corpus
 * n'exige rien de tel au §5 : un numéro professionnel identifie un exercice, pas un patient.
 *
 * Ce silence sera à réexaminer en P6.5b : si un certificat X.509 porte ce numéro dans son sujet,
 * le réattribuer ferait pointer une signature ancienne vers quelqu'un d'autre. La question est
 * ouverte, elle n'est pas tranchée ici — et surtout elle n'est pas préemptée par une table vide
 * qu'on aurait créée « au cas où ».
 */
final class AttributeurNumeroProfessionnel
{
    public function __construct(private readonly GenerateurNumeroProfessionnel $generateur) {}

    /** Renvoie le numéro du professionnel, en l'attribuant s'il n'en a pas. */
    public function attribuer(Medecin $professionnel): string
    {
        if ($professionnel->numero_professionnel !== null) {
            return $professionnel->numero_professionnel;
        }

        return DB::transaction(function () use ($professionnel): string {
            // Relecture sous verrou : deux requêtes simultanées sur la même fiche ne peuvent pas
            // lui attribuer deux numéros et en consommer deux dans la séquence.
            $verrouille = Medecin::query()
                ->whereKey($professionnel->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouille->numero_professionnel !== null) {
                $professionnel->setAttribute('numero_professionnel', $verrouille->numero_professionnel);

                return $verrouille->numero_professionnel;
            }

            $paysCode = $verrouille->pays_code ?? config('referentiels.pays_defaut');
            $numero   = $this->generateur->suivant($paysCode);

            // `forceFill` : le numéro national est HORS `$fillable` — un client ne choisit pas son
            // identifiant national, il le reçoit. Seul ce service l'écrit.
            $verrouille->forceFill([
                'numero_professionnel' => $numero,
                'pays_code'            => $paysCode,
            ])->save();

            $professionnel->setAttribute('numero_professionnel', $numero);
            $professionnel->setAttribute('pays_code', $paysCode);

            return $numero;
        }, 3);
    }
}
