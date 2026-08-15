<?php

namespace Tests\Feature;

use App\Models\CouvertureMembre;
use App\Models\MembreFamille;
use App\Models\OrganismeAssurance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F2.3 — Carte CMU numérique (couche de présentation).
 *
 * Vérifie : le numéro CMU complet ne quitte jamais le serveur (masqué), la carte expose le
 * statut/validité, le code de présentation est signé et ne contient ni numéro ni matricule,
 * le palier « vérifié » gate le code (stub dev), et l'isolation anti-IDOR.
 */
class CarteCmuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * P6.8d — LE MEMBRE N'A PLUS DE COLONNES CMU, IL A UNE COUVERTURE.
     *
     * Les assertions des vecteurs ci-dessous n'ont PAS bougé d'une clé : c'est précisément ce qui
     * prouve que le contrat de la carte F2.3 (module validé G5) survit à la bascule. Seule la façon
     * de fabriquer l'état a changé — la couverture désigne un organisme du registre national, là où
     * trois colonnes nommaient la CMU dans le schéma.
     */
    private function membreCmu(User $user, array $attrs = []): MembreFamille
    {
        $membre = MembreFamille::factory()->for($user)->create(
            array_diff_key($attrs, array_flip(['cmu_numero', 'cmu_statut', 'cmu_validite'])),
        );

        $organisme = OrganismeAssurance::query()->firstOrCreate(
            ['pays_code' => 'CI', 'nom' => 'Caisse Nationale d\'Assurance Maladie'],
            ['sigle' => 'CNAM', 'type' => 'cnam', 'source' => 'demonstration', 'actif' => true],
        );

        $couverture = new CouvertureMembre([
            'organisme_assurance_id' => $organisme->id,
            'numero_assure'          => $attrs['cmu_numero'] ?? 'CMU-1234-5678-9012',
            'date_fin'               => $attrs['cmu_validite'] ?? now()->addYear()->toDateString(),
        ]);
        $couverture->membre_id = $membre->id;
        $couverture->save();

        return $membre->fresh();
    }

    public function test_le_numero_cmu_complet_n_est_jamais_serialise(): void
    {
        $user = User::factory()->create();
        $membre = $this->membreCmu($user);
        Sanctum::actingAs($user);

        $reponse = $this->getJson("/api/v1/membres/{$membre->id}")->assertOk();

        // Numéro complet absent ; seule la version masquée (4 derniers) est exposée.
        $reponse->assertJsonMissingPath('membre.cmu_numero');
        $reponse->assertJsonPath('membre.cmu_numero_masque', '•••• •••• 9012');
    }

    public function test_carte_expose_titulaire_statut_validite_et_masque(): void
    {
        $user = User::factory()->create();
        $membre = $this->membreCmu($user, ['prenom' => 'Awa', 'nom' => 'Koné']);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/carte-cmu")
            ->assertOk()
            ->assertJsonPath('carte.titulaire', 'Awa Koné')
            ->assertJsonPath('carte.cmu_numero_masque', '•••• •••• 9012')
            ->assertJsonPath('carte.cmu_statut', 'actif')
            ->assertJsonPath('carte.disponible', true)
            ->assertJsonPath('carte.expiration_proche', false);
    }

    public function test_code_presentation_signe_sans_numero_ni_matricule(): void
    {
        $user = User::factory()->create();
        $membre = $this->membreCmu($user);
        Sanctum::actingAs($user);

        $code = $this->getJson("/api/v1/membres/{$membre->id}/carte-cmu")
            ->assertOk()->json('carte.code_presentation');

        $this->assertNotNull($code);

        // Ni le numéro CMU complet ni le matricule interne n'apparaissent dans le code.
        $this->assertStringNotContainsString('CMU-1234-5678-9012', $code);
        $this->assertStringNotContainsString($membre->matricule_ivs, $code);

        // Signature HMAC valide (secret à séparation de domaine) et payload = statut déclaré.
        [$corps, $signature] = explode('.', $code);
        $secret = hash_hmac('sha256', 'carte-cmu', (string) config('app.key'));
        $this->assertSame(hash_hmac('sha256', $corps, $secret), $signature);

        $payload = json_decode(base64_decode(strtr($corps, '-_', '+/')), true);
        $this->assertSame('cmu', $payload['typ']);
        $this->assertSame('actif', $payload['st']);
        $this->assertArrayNotHasKey('num', $payload);
    }

    public function test_palier_verifie_gate_le_code_de_presentation(): void
    {
        // En exigeant le palier vérifié, un compte non vérifié n'a pas de code présentable.
        config(['masante.cmu.exiger_palier_verifie' => true]);

        $user = User::factory()->create(); // compte_verifie_at = null
        $membre = $this->membreCmu($user);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/carte-cmu")
            ->assertOk()
            ->assertJsonPath('carte.disponible', false)
            ->assertJsonPath('carte.code_presentation', null);

        // Une fois l'identité confirmée (palier vérifié), la carte devient présentable.
        $user->forceFill(['compte_verifie_at' => now()])->save();

        $carte = $this->getJson("/api/v1/membres/{$membre->id}/carte-cmu")->assertOk()->json('carte');
        $this->assertTrue($carte['disponible']);
        $this->assertNotNull($carte['code_presentation']);
    }

    public function test_expiration_proche_quand_validite_dans_la_fenetre(): void
    {
        $user = User::factory()->create();
        $membre = $this->membreCmu($user, ['cmu_validite' => now()->addDays(10)->toDateString()]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/carte-cmu")
            ->assertOk()->assertJsonPath('carte.expiration_proche', true);
    }

    public function test_carte_cmu_isolation_idor(): void
    {
        $proprietaire = User::factory()->create();
        $membre = $this->membreCmu($proprietaire);

        Sanctum::actingAs(User::factory()->create()); // autre compte

        $this->getJson("/api/v1/membres/{$membre->id}/carte-cmu")->assertForbidden();
    }
}
