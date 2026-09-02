<?php

namespace Tests\Unit;

use App\Support\TypeAccesDossier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B1-c — GARDE ANTI-DIVERGENCE : les voies d'accès au dossier n'existent qu'à UN endroit.
 *
 * `TypeAccesDossier` (PHP, enum natif) et `TypeAccesDossier` (`@masante/shared`, TS) sont tenus à
 * jour à la main depuis P7-D2, sans garde d'exécution — jusqu'ici cinq voies, jamais vérifiées
 * automatiquement. B1-c en ajoute une sixième (`rdv_partage`) des DEUX côtés : c'est l'occasion de
 * fermer ce trou, précédent exact `RendezVousStatutSourceUniqueTest` (B1-a) et
 * `PermissionsSourceUniqueTest` (P11.0).
 *
 * Le test se teste lui-même (motif P5.3b-4, « contrôle toujours vert ») : il vérifie D'ABORD
 * avoir extrait un nombre plausible de valeurs avant de comparer.
 */
class TypeAccesDossierSourceUniqueTest extends TestCase
{
    private const SOURCE_TS = __DIR__.'/../../../../packages/shared/src/enums/index.ts';

    /** En dessous de ce seuil, c'est l'extraction qui a échoué, pas la liste qui a rétréci. */
    private const PLANCHER_PLAUSIBLE = 5;

    #[Test]
    public function la_liste_partagee_est_reellement_extraite(): void
    {
        $partagees = $this->voiesPartagees();

        $this->assertGreaterThanOrEqual(
            self::PLANCHER_PLAUSIBLE,
            count($partagees),
            'Seules '.count($partagees).' valeurs ont été extraites de TypeAccesDossier dans '
            ."index.ts. C'est l'extraction qui a échoué (la forme du fichier a changé), pas la "
            .'liste qui a rétréci.',
        );
    }

    #[Test]
    public function l_enum_php_et_la_source_unique_declarent_exactement_les_memes_voies(): void
    {
        $partagees = $this->voiesPartagees();
        $php = array_map(fn (TypeAccesDossier $c) => $c->value, TypeAccesDossier::cases());
        sort($php);

        $manquantesAuFront = array_values(array_diff($php, $partagees));
        $inventeesAuFront = array_values(array_diff($partagees, $php));

        $this->assertSame(
            [],
            $manquantesAuFront,
            'Ces voies existent dans App\\Support\\TypeAccesDossier (PHP) et sont absentes de '
            .'@masante/shared : '.implode(', ', $manquantesAuFront),
        );

        $this->assertSame(
            [],
            $inventeesAuFront,
            'Ces voies sont déclarées dans @masante/shared::TypeAccesDossier et absentes de '
            .'App\\Support\\TypeAccesDossier (PHP) : '.implode(', ', $inventeesAuFront),
        );
    }

    /** Les valeurs déclarées dans la source unique TypeScript. */
    private function voiesPartagees(): array
    {
        $this->assertFileExists(self::SOURCE_TS, 'La source unique de TypeAccesDossier est introuvable.');

        $source = file_get_contents(self::SOURCE_TS);

        if (preg_match('/export const TypeAccesDossier = \{(.*?)\n\} as const;/s', $source, $corps) !== 1) {
            $this->fail(
                "Le bloc `TypeAccesDossier` n'a pas été trouvé dans index.ts. Si sa forme a "
                .'changé, adaptez cette expression régulière — ne supprimez pas ce test.',
            );
        }

        preg_match_all("/:\s*'([a-z_]+)',/", $corps[1], $trouvees);

        $liste = $trouvees[1];
        sort($liste);

        return $liste;
    }
}
