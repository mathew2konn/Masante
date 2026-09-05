<?php

namespace Tests\Unit;

use App\Support\ModeReglementCommande;
use App\Support\ModeRetraitCommande;
use App\Support\StatutCommande;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B3-d — GARDE ANTI-DIVERGENCE : les trois enums de la commande n'existent qu'à UN endroit.
 *
 * Patron `RendezVousStatutSourceUniqueTest` (B1-a) / `TypeAccesDossierSourceUniqueTest` (P7-D2) /
 * `PermissionsSourceUniqueTest` (P11.0) : web et mobile importent `@masante/shared` littéralement,
 * leur divergence est structurellement impossible (un TypeScript qui ne compile pas la
 * trahirait). Seul PHP, qui ne peut pas importer un fichier TypeScript, a besoin d'une garde
 * d'exécution.
 *
 * Le test se teste lui-même (motif P5.3b-4, « contrôle toujours vert ») : il vérifie D'ABORD avoir
 * extrait un nombre plausible de valeurs avant de comparer, sinon il comparerait deux listes vides
 * et passerait.
 */
class CommandeEnumsSourceUniqueTest extends TestCase
{
    private const SOURCE_TS = __DIR__.'/../../../../packages/shared/src/enums/index.ts';

    #[Test]
    public function les_trois_blocs_sont_reellement_extraits(): void
    {
        $this->assertGreaterThanOrEqual(6, count($this->valeursPartagees('CommandeStatut')));
        $this->assertGreaterThanOrEqual(2, count($this->valeursPartagees('ModeRetraitCommande')));
        $this->assertGreaterThanOrEqual(2, count($this->valeursPartagees('ModeReglementCommande')));
    }

    #[Test]
    public function commande_statut_est_identique_des_deux_cotes(): void
    {
        $this->assertMemesValeurs(
            $this->valeursPartagees('CommandeStatut'),
            $this->valeursEnum(StatutCommande::cases()),
            'CommandeStatut',
        );
    }

    #[Test]
    public function mode_retrait_commande_est_identique_des_deux_cotes(): void
    {
        $this->assertMemesValeurs(
            $this->valeursPartagees('ModeRetraitCommande'),
            $this->valeursEnum(ModeRetraitCommande::cases()),
            'ModeRetraitCommande',
        );
    }

    #[Test]
    public function mode_reglement_commande_est_identique_des_deux_cotes(): void
    {
        $this->assertMemesValeurs(
            $this->valeursPartagees('ModeReglementCommande'),
            $this->valeursEnum(ModeReglementCommande::cases()),
            'ModeReglementCommande',
        );
    }

    /** @param array<int, string> $partagees @param array<int, string> $phpValeurs */
    private function assertMemesValeurs(array $partagees, array $phpValeurs, string $nom): void
    {
        sort($partagees);
        sort($phpValeurs);

        $manquantsAuFront = array_values(array_diff($phpValeurs, $partagees));
        $inventesAuFront = array_values(array_diff($partagees, $phpValeurs));

        $this->assertSame(
            [],
            $manquantsAuFront,
            "Ces valeurs de {$nom} existent côté PHP et sont absentes de @masante/shared : "
            .implode(', ', $manquantsAuFront),
        );

        $this->assertSame(
            [],
            $inventesAuFront,
            "Ces valeurs sont déclarées dans @masante/shared::{$nom} et n'existent pas côté PHP : "
            .implode(', ', $inventesAuFront),
        );
    }

    /** @param array<int, \UnitEnum&\BackedEnum> $cases @return array<int, string> */
    private function valeursEnum(array $cases): array
    {
        return array_map(static fn ($c) => (string) $c->value, $cases);
    }

    /** Les valeurs déclarées dans la source unique TypeScript pour un bloc `export const <nom> = {...}`. */
    private function valeursPartagees(string $nom): array
    {
        $this->assertFileExists(self::SOURCE_TS, 'La source unique des enums est introuvable.');

        $source = file_get_contents(self::SOURCE_TS);

        if (preg_match('/export const '.preg_quote($nom, '/').' = \{(.*?)\n\} as const;/s', $source, $corps) !== 1) {
            $this->fail(
                "Le bloc `{$nom}` n'a pas été trouvé dans index.ts. Si sa forme a changé, adaptez ".
                'cette expression régulière — ne supprimez pas ce test.',
            );
        }

        preg_match_all("/:\s*'([a-z_]+)',/", $corps[1], $trouvees);

        $liste = $trouvees[1];
        sort($liste);

        return $liste;
    }
}
