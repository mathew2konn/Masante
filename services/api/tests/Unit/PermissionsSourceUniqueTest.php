<?php

namespace Tests\Unit;

use Database\Seeders\PortailRolesSeeder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * P11.0 — GARDE ANTI-DIVERGENCE : la liste des permissions n'existe qu'à UN endroit.
 *
 * ── POURQUOI CETTE GARDE ────────────────────────────────────────────────────────────────────
 *
 * Le G0 de P11.0 a mesuré ce que coûte une liste tenue à deux endroits. L'enum `Role` de
 * `@masante/shared` et `PortailRolesSeeder` avaient été écrits séparément, à des modules
 * d'écart, et avaient dérivé au point que **trois rôles dormants doublaient trois rôles
 * vivants**, pendant que les trois rôles qui font réellement tourner le portail ne figuraient
 * pas du tout côté front. Personne ne l'avait vu, parce que rien ne pouvait le voir.
 *
 * Les permissions, elles, vivent désormais aussi des deux côtés : le seeder les crée, le
 * portail Next s'en sert pour n'afficher que ce qui est atteignable. Le même défaut est donc
 * possible — en pire, puisqu'une permission absente du front fait disparaître une entrée de
 * menu **sans erreur**, et qu'une permission inventée côté front affiche une porte qui rendra
 * 403.
 *
 * Ce test est le motif de `NisVecteursPartagesTest` (P6.1), qui garde depuis l'algorithme du
 * NIS de diverger entre TypeScript et PHP : deux implémentations, un seul fichier de
 * référence, et le build casse à la première divergence.
 *
 * ── LE TEST SE TESTE LUI-MÊME ───────────────────────────────────────────────────────────────
 *
 * Il lit un fichier TypeScript par expression régulière, donc il pourrait échouer à trouver
 * quoi que ce soit et « passer » en comparant deux listes vides. C'est exactement le « contrôle
 * toujours vert » refusé en P5.3b-4, et la famille de défauts que ce projet a rencontrée cinq
 * fois (« le vecteur prouve autre chose »). Il vérifie donc D'ABORD qu'il a extrait un nombre
 * plausible d'entrées, et échoue en le disant si la forme du fichier a changé.
 */
class PermissionsSourceUniqueTest extends TestCase
{
    private const SOURCE_TS = __DIR__.'/../../../../packages/shared/src/enums/permissions.ts';

    /** En dessous de ce seuil, c'est l'extraction qui a échoué, pas la liste qui a rétréci. */
    private const PLANCHER_PLAUSIBLE = 30;

    #[Test]
    public function la_liste_partagee_est_reellement_extraite(): void
    {
        $partagees = $this->permissionsPartagees();

        $this->assertGreaterThanOrEqual(
            self::PLANCHER_PLAUSIBLE,
            count($partagees),
            'Seules '.count($partagees).' permissions ont été extraites de permissions.ts. '
            ."C'est l'extraction qui a échoué (la forme du fichier a changé), pas la liste qui a "
            .'rétréci — sans cette vérification, ce test comparerait deux listes vides et passerait.',
        );
    }

    #[Test]
    public function le_seeder_et_la_source_unique_declarent_exactement_les_memes_permissions(): void
    {
        $partagees = $this->permissionsPartagees();
        $seedees = $this->permissionsDuSeeder();

        $manquantesAuFront = array_values(array_diff($seedees, $partagees));
        $inventeesAuFront = array_values(array_diff($partagees, $seedees));

        $this->assertSame(
            [],
            $manquantesAuFront,
            'Ces permissions existent côté backend et sont absentes de @masante/shared : '
            .implode(', ', $manquantesAuFront)
            .". Le portail ne pourra pas afficher les zones qu'elles ouvrent, et l'absence ne "
            .'produira aucune erreur — juste une entrée de menu qui manque.',
        );

        $this->assertSame(
            [],
            $inventeesAuFront,
            'Ces permissions sont déclarées dans @masante/shared et ne sont créées par aucun '
            .'seeder : '.implode(', ', $inventeesAuFront)
            .'. Le portail affichera une porte qui rendra 403 — personne ne les possédera jamais.',
        );
    }

    /** Les permissions déclarées dans la source unique TypeScript. */
    private function permissionsPartagees(): array
    {
        $this->assertFileExists(self::SOURCE_TS, 'La source unique des permissions est introuvable.');

        $source = file_get_contents(self::SOURCE_TS);

        // On ne lit QUE le corps du tableau `PERMISSIONS`, jamais le fichier entier : sinon une
        // permission citée en exemple dans un commentaire entrerait dans la comparaison.
        if (preg_match('/export const PERMISSIONS = \[(.*?)\n\] as const;/s', $source, $corps) !== 1) {
            $this->fail(
                "Le tableau `PERMISSIONS` n'a pas été trouvé dans permissions.ts. Si sa forme a "
                .'changé, adaptez cette expression régulière — ne supprimez pas ce test.',
            );
        }

        preg_match_all("/^\s*'([a-z_.]+)',/m", $corps[1], $trouvees);

        $liste = $trouvees[1];
        sort($liste);

        return $liste;
    }

    /** Les permissions créées par le seeder — lues sur la constante, pas recopiées. */
    private function permissionsDuSeeder(): array
    {
        $constante = (new ReflectionClass(PortailRolesSeeder::class))
            ->getReflectionConstant('PERMISSIONS');

        $this->assertNotFalse(
            $constante,
            'PortailRolesSeeder::PERMISSIONS a disparu ou changé de nom.',
        );

        $liste = $constante->getValue();
        sort($liste);

        return $liste;
    }
}
