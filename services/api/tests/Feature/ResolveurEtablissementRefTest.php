<?php

namespace Tests\Feature;

use App\Models\StructureSanitaire;
use App\Services\ResolveurEtablissementRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B4 (ADR-056, S1) — résout `{pays_code}-{identifiant}` vers l'id local, jamais l'inverse.
 *
 * VECTEUR CENTRAL : deux pays peuvent partager le même `identifiant_national` (P6.4a, unicité
 * `(pays_code, identifiant)`) — sans le préfixe pays, la résolution serait ambiguë.
 */
class ResolveurEtablissementRefTest extends TestCase
{
    use RefreshDatabase;

    private function resolveur(): ResolveurEtablissementRef
    {
        return app(ResolveurEtablissementRef::class);
    }

    private function structure(string $identifiant, string $pays): StructureSanitaire
    {
        $s = StructureSanitaire::create([
            'nom' => 'Structure '.$identifiant.'-'.$pays, 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $s->forceFill(['identifiant_national' => $identifiant, 'pays_code' => $pays])->save();

        return $s;
    }

    public function test_resout_une_reference_valide(): void
    {
        $structure = $this->structure('ETS100001', 'CI');

        $this->assertSame($structure->id, $this->resolveur()->resoudre('CI-ETS100001'));
    }

    public function test_deux_pays_partageant_le_meme_identifiant_ne_sont_pas_confondus(): void
    {
        $ci = $this->structure('ETS100002', 'CI');
        $sn = $this->structure('ETS100002', 'SN');

        $this->assertSame($ci->id, $this->resolveur()->resoudre('CI-ETS100002'));
        $this->assertSame($sn->id, $this->resolveur()->resoudre('SN-ETS100002'));
        $this->assertNotSame($ci->id, $sn->id);
    }

    public function test_reference_introuvable_rend_null(): void
    {
        $this->assertNull($this->resolveur()->resoudre('CI-ETS999999'));
    }

    public function test_reference_malformee_sans_tiret_rend_null(): void
    {
        $this->assertNull($this->resolveur()->resoudre('ETSSANSPAYS'));
    }

    public function test_reference_nulle_ou_vide_rend_null(): void
    {
        $this->assertNull($this->resolveur()->resoudre(null));
        $this->assertNull($this->resolveur()->resoudre(''));
    }
}
