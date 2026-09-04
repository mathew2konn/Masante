<?php

namespace Tests\Unit;

use App\Services\Medicament\ReglesCodeBarres;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * B3-c — `ReglesCodeBarres`, en isolation totale (CDC_11 §7.6).
 *
 * `TestCase` de PHPUnit et non celui de Laravel : cette classe ne touche ni la base, ni l'horloge,
 * ni la configuration. Si l'un de ces vecteurs se mettait à exiger l'application, c'est que la
 * pureté aurait été perdue — le test le dirait avant la revue.
 *
 * CE QU'ELLE PROUVE, ET CE QU'ELLE NE PROUVE PAS (E5) : ces vecteurs vérifient la FORME d'un GTIN
 * et sa clé de contrôle — jamais qu'un produit réel porte ce code. Un GTIN bien formé peut n'avoir
 * jamais été attribué : c'est la limite annoncée du lot, pas un oubli de ce test.
 */
class ReglesCodeBarresTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────────
    // GTIN valides — un par longueur admise
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function gtinValides(): array
    {
        return [
            'GTIN-8'  => ['96385074'],
            'GTIN-12' => ['036000291452'],
            'GTIN-13' => ['4006381333931'],
            'GTIN-14' => ['15400141288763'],
        ];
    }

    #[DataProvider('gtinValides')]
    public function test_un_gtin_bien_forme_est_reconnu(string $code): void
    {
        $this->assertTrue(ReglesCodeBarres::estGtin($code));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La clé de contrôle — le calcul lui-même, indépendamment de estGtin()
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_cle_de_controle_est_calculee_correctement(): void
    {
        // 4006381333931 : la clé (dernier chiffre, 1) doit se retrouver depuis les 12 premiers.
        $this->assertSame(1, ReglesCodeBarres::cleDeControle('400638133393'));
    }

    public function test_une_cle_de_controle_fausse_est_refusee(): void
    {
        // Même 12 premiers chiffres que le vecteur valide ci-dessus, clé changée : 1 → 2.
        $this->assertFalse(ReglesCodeBarres::estGtin('4006381333932'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce qui n'a pas la forme d'un GTIN, chacun pour une raison différente
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_longueur_non_admise_est_refusee(): void
    {
        // 9 chiffres : aucune longueur de GTIN ne vaut 9. CE CODE PRÉCIS N'EST PAS UN HASARD — sa
        // clé de contrôle EST cohérente (cleDeControle('96385074') = 2) : sans la garde de
        // longueur, le calcul l'accepterait quand même. Un vecteur choisi au hasard n'aurait
        // prouvé rien de la garde elle-même (leçon « le vecteur prouve autre chose », P6.7b et
        // suivants) — vérifié par la campagne de mutation de B3-c.
        $this->assertFalse(ReglesCodeBarres::estGtin('963850742'));
    }

    public function test_une_chaine_non_numerique_est_refusee(): void
    {
        $this->assertFalse(ReglesCodeBarres::estGtin('4006381A33931'));
    }

    public function test_une_chaine_vide_est_refusee(): void
    {
        $this->assertFalse(ReglesCodeBarres::estGtin(''));
    }

    public function test_des_espaces_seuls_sont_refuses(): void
    {
        $this->assertFalse(ReglesCodeBarres::estGtin('   '));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Normalisation — le champ de saisie EST le scanner (E6)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_espaces_ordinaires_sont_normalises(): void
    {
        $this->assertTrue(ReglesCodeBarres::estGtin('4 006381 333931'));
    }

    public function test_les_tirets_sont_normalises(): void
    {
        $this->assertTrue(ReglesCodeBarres::estGtin('400-6381-333931'));
    }

    public function test_l_espace_insecable_est_normalise(): void
    {
        // U+00A0 : un copier-coller depuis un document en introduit parfois à la place d'un espace
        // ordinaire — un lecteur de comptoir peut le faire aussi selon son pilote clavier.
        $this->assertTrue(ReglesCodeBarres::estGtin("4006381\u{00A0}333931"));
    }

    public function test_normaliser_retire_les_separateurs_et_les_bords(): void
    {
        $this->assertSame('4006381333931', ReglesCodeBarres::normaliser("  4006-381 333931\t"));
    }
}
