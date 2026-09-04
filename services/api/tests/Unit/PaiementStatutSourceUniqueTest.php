<?php

namespace Tests\Unit;

use App\Support\PaiementStatut;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B4 (ADR-056) — GARDE ANTI-DIVERGENCE : le vocabulaire de `PaiementStatut` n'existe qu'à UN
 * endroit conceptuel (`@masante/shared`), même s'il est désormais répliqué dans trois langages
 * (Java `PaiementStatut`, TypeScript `PaiementStatut`, PHP `App\Support\PaiementStatut`).
 *
 * Même motif que `RendezVousStatutSourceUniqueTest` (B1-a) et `PermissionsSourceUniqueTest`
 * (P11.0) : PHP ne peut pas importer un fichier TypeScript, donc une garde d'exécution compare
 * les deux listes à chaque run. Le test se teste lui-même AVANT de comparer (motif P5.3b-4,
 * « contrôle toujours vert ») : sinon il pourrait comparer deux listes vides et passer.
 */
class PaiementStatutSourceUniqueTest extends TestCase
{
    private const SOURCE_TS = __DIR__.'/../../../../packages/shared/src/enums/index.ts';

    /** En dessous de ce seuil, c'est l'extraction qui a échoué, pas la liste qui a rétréci. */
    private const PLANCHER_PLAUSIBLE = 5;

    #[Test]
    public function la_liste_partagee_est_reellement_extraite(): void
    {
        $partages = $this->statutsPartages();

        $this->assertGreaterThanOrEqual(
            self::PLANCHER_PLAUSIBLE,
            count($partages),
            'Seules '.count($partages).' valeurs ont été extraites de PaiementStatut dans '
            ."index.ts. C'est l'extraction qui a échoué (la forme du fichier a changé), pas la "
            .'liste qui a rétréci.',
        );
    }

    #[Test]
    public function l_enum_php_et_la_source_unique_declarent_exactement_les_memes_statuts(): void
    {
        $partages = $this->statutsPartages();

        $php = array_map(fn (PaiementStatut $c) => $c->value, PaiementStatut::cases());
        sort($php);

        $manquantsAuPhp = array_values(array_diff($partages, $php));
        $inventesAuPhp = array_values(array_diff($php, $partages));

        $this->assertSame(
            [],
            $manquantsAuPhp,
            'Ces statuts sont déclarés dans @masante/shared::PaiementStatut et absents de '
            .'App\\Support\\PaiementStatut : '.implode(', ', $manquantsAuPhp),
        );

        $this->assertSame(
            [],
            $inventesAuPhp,
            'App\\Support\\PaiementStatut déclare des valeurs absentes de @masante/shared : '
            .implode(', ', $inventesAuPhp),
        );
    }

    /** Les valeurs déclarées dans la source unique TypeScript. */
    private function statutsPartages(): array
    {
        $this->assertFileExists(self::SOURCE_TS, 'La source unique de PaiementStatut est introuvable.');

        $source = file_get_contents(self::SOURCE_TS);

        if (preg_match('/export const PaiementStatut = \{(.*?)\n\} as const;/s', $source, $corps) !== 1) {
            $this->fail(
                "Le bloc `PaiementStatut` n'a pas été trouvé dans index.ts. Si sa forme a changé, "
                .'adaptez cette expression régulière — ne supprimez pas ce test.',
            );
        }

        preg_match_all("/:\s*'([A-Z_]+)',/", $corps[1], $trouvees);

        $liste = $trouvees[1];
        sort($liste);

        return $liste;
    }
}
