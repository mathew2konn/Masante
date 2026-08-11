<?php

namespace Tests\Unit;

use App\Services\Nis\CalculateurNis;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * P6.1 — Propriétés du checksum NIS (CDC_09 §3.4).
 *
 * Le CDC_09 exige que le contrôle détecte « les erreurs de saisie, les inversions de chiffres,
 * les faux identifiants ». Ces tests ne se contentent pas de quelques exemples : ils balayent
 * exhaustivement les classes d'erreurs sur un échantillon, ce qui transforme l'exigence du
 * cahier des charges en propriété vérifiée.
 *
 * Test PUR : aucune base, aucun framework.
 */
class CalculateurNisTest extends TestCase
{
    private CalculateurNis $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new CalculateurNis;
    }

    /** @return list<string> Échantillon déterministe (seed fixé → reproductible). */
    private function echantillon(int $taille = 300): array
    {
        mt_srand(7);
        $out = [];
        for ($i = 0; $i < $taille; $i++) {
            $out[] = $this->calc->composer('CIS', mt_rand(24, 30), mt_rand(0, 99_999_999));
        }

        return $out;
    }

    #[Test]
    public function toute_erreur_portant_sur_un_seul_chiffre_est_detectee(): void
    {
        $testes = 0;

        foreach ($this->echantillon(100) as $nis) {
            for ($i = 3; $i < 13; $i++) {          // partie année + compteur
                foreach (range(0, 9) as $d) {
                    if ((string) $d === $nis[$i]) {
                        continue;
                    }
                    $altere = substr_replace($nis, (string) $d, $i, 1);
                    $this->assertFalse(
                        $this->calc->estValide($altere),
                        "Erreur d'un chiffre non détectée : {$nis} → {$altere}"
                    );
                    $testes++;
                }
            }
        }

        $this->assertGreaterThan(8000, $testes, 'Couverture insuffisante.');
    }

    #[Test]
    public function toute_inversion_de_deux_chiffres_voisins_est_detectee(): void
    {
        $testes = 0;

        foreach ($this->echantillon(200) as $nis) {
            for ($i = 3; $i < 14; $i++) {
                if ($nis[$i] === $nis[$i + 1]) {
                    continue;                       // inversion invisible
                }
                $altere = substr_replace($nis, $nis[$i + 1].$nis[$i], $i, 2);
                $this->assertFalse(
                    $this->calc->estValide($altere),
                    "Inversion non détectée : {$nis} → {$altere}"
                );
                $testes++;
            }
        }

        $this->assertGreaterThan(1000, $testes, 'Couverture insuffisante.');
    }

    #[Test]
    public function la_cle_reste_toujours_dans_son_domaine(): void
    {
        foreach ($this->echantillon(500) as $nis) {
            $cle = (int) substr($nis, 13, 2);
            $this->assertGreaterThanOrEqual(2, $cle, "Clé hors domaine : {$nis}");
            $this->assertLessThanOrEqual(98, $cle, "Clé hors domaine : {$nis}");
        }
    }

    #[Test]
    public function deux_pays_ne_produisent_pas_la_meme_cle_pour_les_memes_chiffres(): void
    {
        // Exigence multi-pays (CDC_09 §1.2 principe 5) : le préfixe entre dans le calcul.
        $ci = $this->calc->composer('CIS', 24, 12_000_125);
        $sn = $this->calc->composer('SNS', 24, 12_000_125);

        $this->assertSame(substr($ci, 3, 10), substr($sn, 3, 10), 'Les chiffres doivent être identiques.');
        $this->assertNotSame(substr($ci, 13, 2), substr($sn, 13, 2), 'Les clés doivent différer.');
    }

    #[Test]
    public function la_saisie_est_normalisee_en_majuscules_et_sans_espaces(): void
    {
        $nis = $this->calc->composer('CIS', 24, 12_000_125);

        $this->assertTrue($this->calc->estValide(strtolower($nis)));
        $this->assertTrue($this->calc->estValide('  '.$nis.'  '));
    }

    #[Test]
    public function le_calcul_ne_deborde_pas_sur_le_compteur_maximal(): void
    {
        // Le nombre formé dépasse PHP_INT_MAX : c'est le cas que le modulo chiffre à chiffre
        // doit absorber sans perte de précision.
        $nis = $this->calc->composer('CIS', 99, 99_999_999);

        $this->assertTrue($this->calc->estValide($nis));
        $this->assertSame(15, strlen($nis));
    }
}
