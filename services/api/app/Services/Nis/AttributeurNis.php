<?php

namespace App\Services\Nis;

use App\Models\MembreFamille;
use Illuminate\Support\Facades\DB;

/**
 * Attribution transactionnelle d'un NIS à un dossier patient (CDC_09 §3).
 *
 * INVARIANT : l'écriture du NIS sur le dossier ET son inscription au journal se font dans
 * UNE SEULE transaction. Un NIS posé sur un dossier sans ligne de journal correspondante
 * casserait la garantie de non-réutilisation ; l'inverse produirait un NIS « brûlé » sans
 * porteur. Les deux écritures sont donc indissociables.
 *
 * IDEMPOTENCE : un dossier qui possède déjà un NIS le conserve. Réexécuter l'attribution
 * (rejeu de la commande de backfill, double clic) ne consomme pas la séquence et ne modifie
 * rien — le NIS accompagne le patient toute sa vie (CDC_09 §3.1).
 */
final class AttributeurNis
{
    public const MOTIF_CREATION = 'CREATION_DOSSIER';

    public const MOTIF_BACKFILL = 'BACKFILL';

    public function __construct(private readonly GenerateurNis $generateur) {}

    /**
     * Attribue un NIS au dossier s'il n'en a pas encore, et renvoie le NIS effectif.
     *
     * @param  string  $motif     CREATION_DOSSIER | BACKFILL — tracé au journal.
     * @param  int|null  $acteurId  Utilisateur à l'origine de l'attribution (null = système).
     */
    public function attribuer(MembreFamille $membre, string $motif = self::MOTIF_CREATION, ?int $acteurId = null): string
    {
        // Idempotence : jamais de réattribution, jamais de consommation de séquence en double.
        if ($membre->nis !== null) {
            return $membre->nis;
        }

        // 3 tentatives : Laravel rejoue automatiquement la transaction sur deadlock. L'ordre de
        // verrou de `GenerateurNis` en supprime la cause connue ; ce rejeu couvre le résiduel
        // (verrous pris par un autre chemin sur les mêmes lignes). Défense en profondeur.
        return DB::transaction(function () use ($membre, $motif, $acteurId) {
            // Relecture verrouillée : si deux requêtes concurrentes visent le même dossier,
            // la seconde voit le NIS déjà posé par la première et sort par l'idempotence.
            $verrouille = MembreFamille::whereKey($membre->getKey())->lockForUpdate()->first();

            if ($verrouille !== null && $verrouille->nis !== null) {
                $membre->setAttribute('nis', $verrouille->nis);

                return $verrouille->nis;
            }

            $paysCode = $membre->pays_code ?? 'CI';
            $genere   = $this->generateur->suivant($paysCode);
            $moment   = now();

            $membre->forceFill([
                'nis'             => $genere['nis'],
                'nis_attribue_le' => $moment,
                'pays_code'       => $paysCode,
            ])->save();

            // Journal append-only : le NIS y reste même si le dossier est supprimé ensuite.
            DB::table('nis_journal')->insert([
                'nis'         => $genere['nis'],
                'membre_id'   => $membre->getKey(),
                'pays_code'   => $paysCode,
                'attribue_le' => $moment,
                'motif'       => $motif,
                'acteur_id'   => $acteurId,
            ]);

            return $genere['nis'];
        }, 3);
    }
}
