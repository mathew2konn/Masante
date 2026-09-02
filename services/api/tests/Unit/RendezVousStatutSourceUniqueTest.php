<?php

namespace Tests\Unit;

use App\Services\RendezVousValidationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B1-a — GARDE ANTI-DIVERGENCE : les statuts de RDV n'existent qu'à UN endroit.
 *
 * Le G0 de B1 a trouvé que `RendezVousStatut` (`@masante/shared`) portait sept valeurs, dont
 * `PREVALIDE_SECRETAIRE`, et n'était importé NULLE PART dans le monorepo — le vrai contrat (cinq
 * valeurs, aucune pré-validation) était dupliqué INDÉPENDAMMENT trois fois : ce service PHP,
 * `apps/web/src/lib/rdv-types.ts`, `apps/mobile/src/types/structure.ts`. Même précédent que
 * `TypeAccesDossier` (P7-D2) et les permissions (P11.0, `PermissionsSourceUniqueTest`).
 *
 * Web et mobile important désormais LITTÉRALEMENT le type partagé (`import type {
 * RendezVousStatut } from '@masante/shared'`), leur divergence est structurellement impossible —
 * un TypeScript qui ne compile pas la trahirait. Seul PHP, qui ne peut pas importer un fichier
 * TypeScript, a besoin d'une garde d'exécution : celle-ci.
 *
 * Le test se teste lui-même (motif P5.3b-4, « contrôle toujours vert ») : il vérifie D'ABORD
 * avoir extrait un nombre plausible de valeurs avant de comparer, sinon il comparerait deux
 * listes vides et passerait.
 */
class RendezVousStatutSourceUniqueTest extends TestCase
{
    private const SOURCE_TS = __DIR__.'/../../../../packages/shared/src/enums/index.ts';

    /** En dessous de ce seuil, c'est l'extraction qui a échoué, pas la liste qui a rétréci. */
    private const PLANCHER_PLAUSIBLE = 5;

    #[Test]
    public function la_liste_partagee_est_reellement_extraite(): void
    {
        $partagees = $this->statutsPartages();

        $this->assertGreaterThanOrEqual(
            self::PLANCHER_PLAUSIBLE,
            count($partagees),
            'Seules '.count($partagees).' valeurs ont été extraites de RendezVousStatut dans '
            ."index.ts. C'est l'extraction qui a échoué (la forme du fichier a changé), pas la "
            .'liste qui a rétréci.',
        );
    }

    #[Test]
    public function le_service_et_la_source_unique_declarent_exactement_les_memes_statuts(): void
    {
        $partages = $this->statutsPartages();
        $service = RendezVousValidationService::STATUTS;
        sort($service);

        $manquantsAuFront = array_values(array_diff($service, $partages));
        $inventesAuFront = array_values(array_diff($partages, $service));

        $this->assertSame(
            [],
            $manquantsAuFront,
            'Ces statuts existent dans RendezVousValidationService::STATUTS et sont absents de '
            .'@masante/shared : '.implode(', ', $manquantsAuFront),
        );

        $this->assertSame(
            [],
            $inventesAuFront,
            'Ces statuts sont déclarés dans @masante/shared::RendezVousStatut et ne sont produits '
            .'par aucune transition du service : '.implode(', ', $inventesAuFront),
        );
    }

    /** Les valeurs déclarées dans la source unique TypeScript. */
    private function statutsPartages(): array
    {
        $this->assertFileExists(self::SOURCE_TS, 'La source unique de RendezVousStatut est introuvable.');

        $source = file_get_contents(self::SOURCE_TS);

        if (preg_match('/export const RendezVousStatut = \{(.*?)\n\} as const;/s', $source, $corps) !== 1) {
            $this->fail(
                "Le bloc `RendezVousStatut` n'a pas été trouvé dans index.ts. Si sa forme a "
                .'changé, adaptez cette expression régulière — ne supprimez pas ce test.',
            );
        }

        preg_match_all("/:\s*'([a-z_]+)',/", $corps[1], $trouvees);

        $liste = $trouvees[1];
        sort($liste);

        return $liste;
    }
}
