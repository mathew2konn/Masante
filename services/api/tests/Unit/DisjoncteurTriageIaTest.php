<?php

namespace Tests\Unit;

use App\Services\Triage\DisjoncteurTriageIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P10c-2-i (F8) — Le disjoncteur vers `triage-service`, isolé de tout appel réseau réel.
 *
 * Trois états, un seul vecteur par transition — voir {@see DisjoncteurTriageIa} pour pourquoi
 * « demi-ouvert » n'a pas de clé de cache propre.
 */
class DisjoncteurTriageIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'masante.triage_ia.disjoncteur_seuil_echecs' => 3,
            'masante.triage_ia.disjoncteur_duree_ouverture_s' => 60,
        ]);
    }

    public function test_ferme_au_depart(): void
    {
        $this->assertFalse((new DisjoncteurTriageIa)->estOuvert());
    }

    public function test_reste_ferme_juste_sous_le_seuil(): void
    {
        $disjoncteur = new DisjoncteurTriageIa;

        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();

        $this->assertFalse($disjoncteur->estOuvert());
    }

    public function test_s_ouvre_au_seuil_exact(): void
    {
        $disjoncteur = new DisjoncteurTriageIa;

        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();

        $this->assertTrue($disjoncteur->estOuvert());
    }

    public function test_un_succes_referme_et_efface_le_compteur(): void
    {
        $disjoncteur = new DisjoncteurTriageIa;
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();
        $this->assertTrue($disjoncteur->estOuvert());

        $disjoncteur->enregistrerSucces();

        $this->assertFalse($disjoncteur->estOuvert());

        // Le compteur est vraiment effacé, pas seulement le circuit refermé : deux nouveaux échecs
        // ne doivent pas rouvrir immédiatement (il en faudrait de nouveau trois).
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();
        $this->assertFalse($disjoncteur->estOuvert());
    }

    public function test_demi_ouvert_un_succes_apres_expiration_referme(): void
    {
        $disjoncteur = new DisjoncteurTriageIa;
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();
        $this->assertTrue($disjoncteur->estOuvert());

        $this->travel(61)->seconds();
        // La durée d'ouverture est passée : demi-ouvert, l'appel suivant sert d'essai.
        $this->assertFalse($disjoncteur->estOuvert());

        $disjoncteur->enregistrerSucces();
        $this->assertFalse($disjoncteur->estOuvert());
    }

    public function test_demi_ouvert_un_echec_apres_expiration_rouvre(): void
    {
        $disjoncteur = new DisjoncteurTriageIa;
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();
        $disjoncteur->enregistrerEchec();

        $this->travel(61)->seconds();
        $this->assertFalse($disjoncteur->estOuvert());

        // L'essai de la demi-ouverture échoue : le circuit se ROUVRE.
        $disjoncteur->enregistrerEchec();

        $this->assertTrue($disjoncteur->estOuvert());
    }
}
