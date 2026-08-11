<?php

namespace Tests\Unit;

use App\Services\Nis\CalculateurNis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * P6.1 — GARDE ANTI-DIVERGENCE TS ↔ PHP (ADR-021 §5).
 *
 * Le CDC_09 §3.4 impose que le NIS soit validé côté client ET côté serveur. Deux
 * implémentations = risque de divergence silencieuse : le mobile accepterait un NIS que le
 * serveur refuse, ou l'inverse.
 *
 * Ce test consomme le MÊME fichier que la suite TypeScript :
 *     packages/shared/src/nis/vecteurs.json
 *
 * Si l'une des deux implémentations dérive, ce test casse. C'est le seul mécanisme qui rende
 * la règle « source unique » (CDC_02 §2.2) vérifiable par la CI plutôt que par la discipline.
 *
 * Test PUR : aucune base, aucun conteneur Laravel (hérite de PHPUnit\TestCase, pas de Tests\TestCase).
 */
class NisVecteursPartagesTest extends TestCase
{
    private const CHEMIN_VECTEURS = __DIR__.'/../../../../packages/shared/src/nis/vecteurs.json';

    private CalculateurNis $calculateur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculateur = new CalculateurNis;
    }

    /** @return array<string, mixed> */
    private static function vecteurs(): array
    {
        $chemin = self::CHEMIN_VECTEURS;

        if (! is_file($chemin)) {
            self::fail(
                "Vecteurs partagés introuvables : {$chemin}. "
                .'Le contrat TS ↔ PHP ne peut pas être vérifié.'
            );
        }

        return json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function le_fichier_de_vecteurs_partages_est_present_et_non_vide(): void
    {
        $v = self::vecteurs();

        $this->assertNotEmpty($v['valides'], 'Aucun vecteur valide : la garde ne protège rien.');
        $this->assertNotEmpty($v['invalides'], 'Aucun vecteur invalide : les rejets ne sont pas couverts.');
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function vecteursValides(): iterable
    {
        foreach (self::vecteurs()['valides'] as $cas) {
            yield $cas['nis'] => [$cas['nis'], $cas['prefixe'], $cas['annee'], $cas['compteur']];
        }
    }

    #[Test]
    #[DataProvider('vecteursValides')]
    public function chaque_nis_de_reference_est_accepte(string $nis, string $prefixe, string $annee, string $compteur): void
    {
        $resultat = $this->calculateur->verifier($nis);

        $this->assertTrue(
            $resultat['valide'],
            "Le NIS de référence {$nis} est rejeté par PHP (motif : ".($resultat['motif'] ?? '?').')'
        );

        // La clé recalculée doit correspondre : c'est l'égalité algorithmique avec le TS.
        $this->assertSame(
            substr($nis, 13, 2),
            $this->calculateur->calculerCle($prefixe, $annee, $compteur),
            "Clé recalculée divergente pour {$nis}."
        );

        // La recomposition depuis les segments doit redonner le NIS à l'identique.
        $this->assertSame(
            $nis,
            $this->calculateur->composer($prefixe, (int) $annee, (int) $compteur),
            "La recomposition de {$nis} ne redonne pas la même valeur."
        );
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function vecteursInvalides(): iterable
    {
        foreach (self::vecteurs()['invalides'] as $i => $cas) {
            yield ($cas['nis'] === '' ? "vide#{$i}" : $cas['nis']) => [$cas['nis'], $cas['motif']];
        }
    }

    #[Test]
    #[DataProvider('vecteursInvalides')]
    public function chaque_nis_invalide_est_rejete_avec_le_bon_motif(string $nis, string $motifAttendu): void
    {
        $resultat = $this->calculateur->verifier($nis);

        $this->assertFalse($resultat['valide'], "Le NIS invalide « {$nis} » a été accepté.");
        $this->assertSame(
            $motifAttendu,
            $resultat['motif'],
            "Motif de rejet divergent pour « {$nis} »."
        );
    }
}
