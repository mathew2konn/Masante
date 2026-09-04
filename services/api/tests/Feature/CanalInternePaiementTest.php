<?php

namespace Tests\Feature;

use App\Models\CommissionTransaction;
use App\Models\FacturePatient;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\CommissionService;
use App\Support\MomentPaiement;
use App\Support\StatutFacturePatient;
use Database\Seeders\BaremesCommissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot 6 (v2), volet 1 — canal interne paiement-service (Java) → Laravel.
 *
 * Le principal signé est construit ICI de façon indépendante de tout code applicatif : on veut
 * prouver que `VerificateurPrincipalSigne` accepte/rejette un principal conforme à l'algorithme
 * documenté dans `paiement.ts`, pas seulement ce que produirait notre propre signeur — un bug
 * partagé entre les deux ne serait sinon jamais vu.
 *
 * ═══ CORRECTION DU 2026-09-04 (B4, ADR-056) ═══
 * Cette suite affirmait jusqu'ici que l'endpoint « ne déclenche RIEN » — vrai en v2 initiale, faux
 * depuis B4 : Laravel devient émetteur (canal GeniusPay) et l'endpoint calcule DÉSORMAIS une
 * commission, mais SEULEMENT quand `canal === 'geniuspay'` ET `statut === 'SUCCESS'` ET
 * `etablissementRef` se résout à une structure réelle. Le test n°3, historique, prouvait que
 * l'ancien payload v1 (`statut: 'REUSSIE'`, sans `canal`) ne déclenche RIEN — ce qui reste vrai et
 * illustre la garde, mais ne suffit plus à décrire tout le contrat. Les vecteurs B4 (fin de fichier)
 * prouvent le déclenchement RÉEL, et ses refus, chacun par son motif.
 */
class CanalInternePaiementTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/interne/v1/paiements/notification';

    private const CHEMIN_SIGNE = '/api/interne/v1/paiements/notification';

    private const SECRET_B64 = 'dGVzdC1zZWNyZXQtbG90Ni1jYW5hbC1pbnRlcm5l'; // même valeur que phpunit.xml

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Structure de test '.uniqid(), 'type' => 'chu', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function factureARegler(StructureSanitaire $s, int $montant = 15000): FacturePatient
    {
        return FacturePatient::create([
            'structure_sanitaire_id' => $s->id,
            'patient_id' => User::factory()->create()->id,
            'reference' => 'FPA-'.uniqid(),
            'moment_paiement' => MomentPaiement::AVANT_ACTE,
            'montant_brut' => $montant,
            'montant_pris_en_charge_cmu' => 0,
            'montant_reste_a_charge' => $montant,
            'statut' => StatutFacturePatient::A_REGLER,
            'paiement_en_ligne_autorise' => true,
            'date_emission' => now(),
        ]);
    }

    /** Construit un principal signé conforme à l'algorithme documenté par `paiement.ts`. */
    private function entetesSignees(string $methode = 'POST', string $chemin = self::CHEMIN_SIGNE): array
    {
        $maintenant = time();
        $claims = [
            'sub' => 'paiement-service-test',
            'roles' => ['SYSTEME'],
            'iat' => $maintenant,
            'exp' => $maintenant + 120,
            'method' => $methode,
            'path' => $chemin,
            'nonce' => (string) Str::uuid(),
        ];

        $principalB64 = base64_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $secret = base64_decode(self::SECRET_B64, true) ?: '';
        $signature = base64_encode(hash_hmac('sha256', $principalB64, $secret, true));

        return ['X-Principal' => $principalB64, 'X-Principal-Sig' => $signature];
    }

    /** Le payload réel du volet 2 : frais TOUJOURS à 0, explicites, jamais omis ni estimés. */
    private function payload(string $statut = 'REUSSIE', array $extra = []): array
    {
        return array_merge([
            'correlationId' => 'CORR-'.uniqid(),
            'montant' => 15000,
            'statut' => $statut,
            'dateTransaction' => now()->toIso8601String(),
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
        ], $extra);
    }

    // ── 1. Signature valide acceptée et journalisée ─────────────────────────────────────────

    public function test_signature_valide_acceptee_et_journalisee(): void
    {
        Log::spy();

        $correlationId = 'CORR-'.uniqid();

        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payload('REUSSIE', ['correlationId' => $correlationId]))
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $contexte) use ($correlationId) {
                return str_contains($message, 'Notification paiement-service')
                    && ($contexte['correlationId'] ?? null) === $correlationId
                    && ($contexte['statut'] ?? null) === 'REUSSIE'
                    && ($contexte['montant'] ?? null) === 15000
                    && isset($contexte['recu_le']);
            })
            ->once();
    }

    // ── 2. Signature invalide rejetée 401 ───────────────────────────────────────────────────

    public function test_signature_invalide_rejetee_401(): void
    {
        $entetes = $this->entetesSignees();
        $entetes['X-Principal-Sig'] = base64_encode('signature-forgee-invalide');

        $this->withHeaders($entetes)
            ->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(401)
            // Corps vide de détail : un message de refus ne doit rien apprendre à qui forge.
            ->assertJsonMissingPath('motif');
    }

    // ── 3. Un payload à l'ANCIEN vocabulaire (v1) ne déclenche toujours rien ────────────────

    public function test_payload_ancien_vocabulaire_sans_canal_ne_declenche_aucune_commission(): void
    {
        // Barèmes présents : si l'endpoint appelait le service, le calcul aboutirait vraiment —
        // l'absence de commission ne pourrait donc pas être mise sur le compte d'un barème manquant.
        $this->seed(BaremesCommissionSeeder::class);

        $this->mock(CommissionService::class, function ($mock) {
            $mock->shouldNotReceive('calculerEtEnregistrer');
        });

        $facture = $this->factureARegler($this->structure());

        // Payload de l'ANCIEN contrat (v1) : statut au vocabulaire français, AUCUN `canal` — la
        // v1 créait ici une commission et passait la facture à PAYEE. Le contrat v2/B4 les ignore
        // TOUJOURS : `statut` n'est jamais `SUCCESS` (le vrai vocabulaire) ET `canal` est absent, donc
        // aucune des deux conditions du déclenchement B4 n'est réunie.
        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payload('REUSSIE', [
                'facturePatientId' => $facture->id,
                'referenceInterne' => 'MS-'.uniqid(),
                'fraisPasserelle' => 100,
                'fraisPrestataire' => 200,
            ]))
            ->assertOk();

        $this->assertSame(0, CommissionTransaction::query()->count());
        $this->assertSame(
            StatutFacturePatient::A_REGLER,
            $facture->fresh()->statut,
            'Ce lot transporte : il ne touche à aucun état métier de facture.'
        );
    }

    // ── 4. Le contrat n'exige ni frais ni facturePatientId ─────────────────────────────────

    public function test_payload_sans_frais_ni_facture_patient_id_accepte(): void
    {
        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, [
                'correlationId' => 'CORR-'.uniqid(),
                'montant' => 15000,
                'statut' => 'ECHOUEE',
                'dateTransaction' => now()->toIso8601String(),
            ])
            ->assertOk();
    }

    // ── VECTEUR PARTAGÉ Java ⇄ PHP ─────────────────────────────────────────────────────────

    /**
     * Les tests de cette classe prouvent PHP ⇄ PHP ; ceux de `SigneurPrincipalSortantTest` (Java)
     * prouvent Java ⇄ Java. Il manquait le seul segment qui existe en production : **Java signe,
     * PHP vérifie**.
     *
     * Ce vecteur le ferme sans harnais inter-langages : la même paire (chaîne à signer → HMAC
     * attendu) est assertée ici ET côté Java. Si l'une des deux implémentations dérive — encodage
     * du secret, portée du HMAC, base64 —, son test tombe seul, et l'on sait immédiatement lequel
     * des deux côtés a bougé. Même motif que les vecteurs partagés du NIS (P6.1), pour la même
     * raison : une garantie inter-langages ne tient pas si chaque langage se relit lui-même.
     *
     * Seule la SIGNATURE est figée, jamais une vérification complète : les claims portent des
     * horodatages, et un vecteur figé qui les traverserait échouerait le jour où on le rejoue.
     */
    public function test_vecteur_partage_avec_le_signeur_java(): void
    {
        $principalB64 = 'eyJzdWIiOiJwYWllbWVudC1zZXJ2aWNlIiwicm9sZXMiOlsiU1lTVEVNRSJdLCJpYXQi'
            .'OjE3OTgwMDAwMDAsImV4cCI6MTc5ODAwMDEyMCwibWV0aG9kIjoiUE9TVCIsInBhdGgiOiIvYXBpL2lu'
            .'dGVybmUvdjEvcGFpZW1lbnRzL25vdGlmaWNhdGlvbiIsIm5vbmNlIjoiMTExMTExMTEtMjIyMi0zMzMz'
            .'LTQ0NDQtNTU1NTU1NTU1NTU1In0=';
        $secretB64 = 'cHJpbmNpcGFsLWRldi1zZWNyZXQtMDEyMzQ1Njc4OSEh'; // secret de dév du paiement-service

        $signature = base64_encode(hash_hmac('sha256', $principalB64, base64_decode($secretB64, true), true));

        $this->assertSame('duiZUC7woP0XOLmmyKvU+lQHQ6iUbzKR5Jt6ZExvStg=', $signature);

        // Et le principal se relit : c'est bien le chemin que le vérificateur exige, au caractère près.
        $claims = json_decode(base64_decode($principalB64, true), true);
        $this->assertSame(self::CHEMIN_SIGNE, $claims['path']);
        $this->assertSame('POST', $claims['method']);
    }

    // ── B4 (ADR-056, 2026-09-04) — le déclenchement RÉEL de la commission ──────────────────

    private function structureAvecIdentifiant(string $identifiant, string $pays = 'CI'): StructureSanitaire
    {
        $structure = StructureSanitaire::create([
            'nom' => 'Structure de test '.uniqid(), 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $structure->forceFill(['identifiant_national' => $identifiant, 'pays_code' => $pays])->save();

        return $structure;
    }

    /** Payload B4 : vocabulaire RÉEL (`SUCCESS`), `canal` et `etablissementRef` présents. */
    private function payloadGeniusPay(array $extra = []): array
    {
        return $this->payload('SUCCESS', array_merge([
            'paiementId' => (string) Str::uuid(),
            'canal' => 'geniuspay',
            'etablissementRef' => 'CI-ETS900001',
        ], $extra));
    }

    public function test_succes_geniuspay_etablissement_resolu_declenche_une_commission(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $structure = $this->structureAvecIdentifiant('ETS900001');

        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payloadGeniusPay([
                'montant' => 10000,
                'fraisPasserelle' => 100,
                'fraisPrestataire' => 200,
            ]))
            ->assertOk();

        $this->assertSame(1, CommissionTransaction::query()->count());
        $commission = CommissionTransaction::sole();
        $this->assertSame($structure->id, $commission->structure_sanitaire_id);
        $this->assertSame(10000, $commission->montant_brut);
        $this->assertSame(250, $commission->taux_bps_applique, 'Volume 0 => palier 1 (250 bps).');
        $this->assertTrue($commission->frais_connus);
    }

    public function test_succes_carte_meme_avec_etablissement_ne_declenche_aucune_commission(): void
    {
        // LE VECTEUR CENTRAL DE LA CORRECTION : trouvé en implémentant, pas au G1. La carte porte
        // ELLE AUSSI un etablissementRef (ServiceCarte, Java) — seul le canal doit décider.
        $this->seed(BaremesCommissionSeeder::class);
        $this->structureAvecIdentifiant('ETS900002');

        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payload('SUCCESS', [
                'paiementId' => (string) Str::uuid(),
                'canal' => 'carte',
                'etablissementRef' => 'CI-ETS900002',
                'montant' => 10000,
            ]))
            ->assertOk();

        $this->assertSame(
            0,
            CommissionTransaction::query()->count(),
            "Aucune politique de commission n'a jamais été décidée pour le canal carte."
        );
    }

    public function test_succes_mobile_money_ne_declenche_aucune_commission(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $this->structureAvecIdentifiant('ETS900003');

        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payload('SUCCESS', [
                'paiementId' => (string) Str::uuid(),
                'canal' => 'orange_money',
                'etablissementRef' => 'CI-ETS900003',
                'montant' => 10000,
            ]))
            ->assertOk();

        $this->assertSame(0, CommissionTransaction::query()->count());
    }

    public function test_etablissement_ref_inconnu_ne_declenche_rien_et_journalise(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        Log::spy();

        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payloadGeniusPay(['etablissementRef' => 'CI-ETS999999']))
            ->assertOk();

        $this->assertSame(0, CommissionTransaction::query()->count());
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $m, array $c) => str_contains($m, 'etablissementRef inconnu')
                && ($c['etablissementRef'] ?? null) === 'CI-ETS999999')
            ->once();
    }

    public function test_echec_geniuspay_ne_declenche_aucune_commission(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $this->structureAvecIdentifiant('ETS900004');

        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payload('FAILED', [
                'paiementId' => (string) Str::uuid(),
                'canal' => 'geniuspay',
                'etablissementRef' => 'CI-ETS900004',
                'montant' => 10000,
            ]))
            ->assertOk();

        $this->assertSame(0, CommissionTransaction::query()->count());
    }

    public function test_frais_inconnus_commission_calculee_a_zero_et_la_ligne_le_dit(): void
    {
        // Décision du propriétaire (2026-09-04) : calculer plutôt que refuser, mais jamais laisser
        // croire que 0 était une valeur connue.
        $this->seed(BaremesCommissionSeeder::class);
        $this->structureAvecIdentifiant('ETS900005');

        $this->withHeaders($this->entetesSignees())
            ->postJson(self::ENDPOINT, $this->payloadGeniusPay([
                'etablissementRef' => 'CI-ETS900005',
                'montant' => 10000,
                'fraisPasserelle' => null,
            ]))
            ->assertOk();

        $commission = CommissionTransaction::sole();
        $this->assertSame(0, $commission->frais_passerelle);
        $this->assertFalse($commission->frais_connus);
        $this->assertSame(250, $commission->montant_commission, 'La commission se calcule quand même.');
    }

    public function test_rejeu_de_la_meme_notification_ne_cree_pas_une_seconde_commission(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $this->structureAvecIdentifiant('ETS900006');
        $paiementId = (string) Str::uuid();

        $payload = $this->payload('SUCCESS', [
            'paiementId' => $paiementId,
            'canal' => 'geniuspay',
            'etablissementRef' => 'CI-ETS900006',
            'montant' => 10000,
        ]);

        $this->withHeaders($this->entetesSignees())->postJson(self::ENDPOINT, $payload)->assertOk();
        // Second envoi : même paiementId, montant totalement différent. S'il était recalculé, la
        // ligne changerait — c'est l'idempotence de CommissionService (clé) qui protège ici.
        $rejeu = array_merge($payload, ['montant' => 999999]);
        $this->withHeaders($this->entetesSignees())->postJson(self::ENDPOINT, $rejeu)->assertOk();

        $this->assertSame(1, CommissionTransaction::query()->count());
        $this->assertSame(10000, CommissionTransaction::sole()->montant_brut, 'Aucun recalcul au rejeu.');
    }
}
