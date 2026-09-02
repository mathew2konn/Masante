<?php

namespace Tests\Feature;

use App\Models\ClientApi;
use App\Models\CorrespondancePartenaire;
use App\Models\JournalIngestion;
use App\Models\Medicament;
use App\Models\PrixPharmacie;
use App\Models\StructureSanitaire;
use App\Services\Integration\IngestionStockOfficine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P11.2 — L'API d'ingestion partenaire (CDC_11 §2/§7.7, ADR-030).
 *
 * Trois familles de garanties : **qui** peut écrire (la troisième population
 * d'authentification), **ce qui** est écrit (le serveur ne devine jamais, et passe par le service
 * existant), et **ce qui est dit** de ce qui a échoué (rapport nominatif, journal).
 */
class ApiIngestionPartenaireTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/integration/stock-officine';

    private StructureSanitaire $pharmacie;

    private ClientApi $client;

    private string $secret;

    private Medicament $paracetamol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharmacie = StructureSanitaire::create([
            'nom' => 'Pharmacie du Plateau', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Plateau', 'latitude' => 5.32, 'longitude' => -4.02, 'actif' => true,
        ]);

        $this->paracetamol = Medicament::create([
            'nom_generique' => 'Paracétamol', 'forme' => 'comprime', 'dosage' => '500 mg', 'categorie' => 'antalgique',
            'voie_administration' => 'orale', 'statut_marche' => 'autorise',
            'prix_reference_cfa' => 500, 'ordonnance_requise' => false,
        ]);
        // Le PIVOT : sans code national, aucune correspondance n'est déclarable (constat du G0).
        DB::table('medicaments')->where('id', $this->paracetamol->id)
            ->update(['code' => 'MED000001', 'pays_code' => 'CI']);
        $this->paracetamol->refresh();

        $this->secret = ClientApi::genererSecret();
        $this->client = new ClientApi([
            'structure_id' => $this->pharmacie->id,
            'libelle' => 'Caisse Sage Officine v4',
            'domaines_json' => [IngestionStockOfficine::DOMAINE],
        ]);
        $this->client->identifiant = ClientApi::genererIdentifiant();
        $this->client->secret_chiffre = $this->secret;
        $this->client->save();
    }

    /** Envoie un lot signé comme le ferait le logiciel du partenaire. */
    private function envoyer(
        array $lignes,
        ?string $secret = null,
        ?string $identifiant = null,
        ?int $horodatage = null,
        ?string $idempotencyKey = null,
    ) {
        $corps = json_encode(['lignes' => $lignes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = $horodatage ?? now()->timestamp;
        $signature = base64_encode(hash_hmac('sha256', $ts.'.'.$corps, $secret ?? $this->secret, true));

        $entetes = [
            'X-MaSante-Client' => $identifiant ?? $this->client->identifiant,
            'X-MaSante-Timestamp' => (string) $ts,
            'X-MaSante-Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $entetes['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->call('POST', self::URL, [], [], [], $this->serveur($entetes), $corps);
    }

    /** @return array<string, string> */
    private function serveur(array $entetes): array
    {
        $serveur = [];
        foreach ($entetes as $nom => $valeur) {
            $cle = strtoupper(str_replace('-', '_', $nom));
            $serveur[in_array($nom, ['Content-Type', 'Accept'], true) ? $cle : 'HTTP_'.$cle] = $valeur;
        }

        return $serveur;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Qui peut écrire : la troisième population
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function un_envoi_signe_est_accepte(): void
    {
        $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 30, 'prix_cfa' => 500],
        ])->assertOk()->assertJsonPath('acceptees', 1)->assertJsonPath('refusees', 0);
    }

    #[Test]
    public function une_signature_fausse_est_refusee_sans_dire_pourquoi(): void
    {
        $reponse = $this->envoyer(
            [['reference' => 'PARA500', 'code_masante' => 'MED000001', 'prix_cfa' => 500]],
            secret: ClientApi::genererSecret(),
        );

        $reponse->assertStatus(401);

        // Le motif exact est journalisé, jamais renvoyé : un attaquant ne doit rien apprendre de
        // la raison du refus (même règle que `VerificateurPrincipalSigne`).
        $this->assertStringNotContainsStringIgnoringCase('signature', $reponse->json('message') ?? '');
    }

    #[Test]
    public function un_identifiant_inconnu_est_refuse_comme_une_signature_fausse(): void
    {
        // Les deux refus doivent se ressembler : distinguer « client inconnu » de « signature
        // fausse » dirait à un attaquant quels identifiants existent.
        $this->envoyer(
            [['reference' => 'PARA500', 'code_masante' => 'MED000001', 'prix_cfa' => 500]],
            identifiant: 'API-INEXISTANT',
        )->assertStatus(401);
    }

    #[Test]
    public function un_envoi_hors_fenetre_de_fraicheur_est_refuse(): void
    {
        // Sans fraîcheur, l'anti-rejeu devrait mémoriser indéfiniment : un envoi capté serait
        // rejouable des mois plus tard.
        $this->envoyer(
            [['reference' => 'PARA500', 'code_masante' => 'MED000001', 'prix_cfa' => 500]],
            horodatage: now()->subHour()->timestamp,
        )->assertStatus(401);
    }

    #[Test]
    public function le_meme_envoi_presente_deux_fois_est_refuse_au_rejeu(): void
    {
        $lignes = [['reference' => 'PARA500', 'code_masante' => 'MED000001', 'prix_cfa' => 500]];
        $ts = now()->timestamp;

        $this->envoyer($lignes, horodatage: $ts)->assertOk();
        // Exactement les mêmes octets et la même signature : c'est le rejeu, pas un second envoi.
        $this->envoyer($lignes, horodatage: $ts)->assertStatus(401);
    }

    #[Test]
    public function un_client_revoque_ne_peut_plus_ecrire(): void
    {
        $this->client->forceFill(['revoque_le' => now(), 'revoque_motif' => 'Contrat résilié.'])->save();

        $this->envoyer([['reference' => 'PARA500', 'code_masante' => 'MED000001', 'prix_cfa' => 500]])
            ->assertStatus(401);
    }

    #[Test]
    public function une_cle_ne_peut_pas_alimenter_un_domaine_qui_ne_lui_est_pas_ouvert(): void
    {
        // Deux gardes distinctes, pas une seule à deux effets : la signature dit « ce n'est pas
        // vous », le domaine dit « ce n'est pas à vous ».
        $this->client->forceFill(['domaines_json' => []])->save();

        $this->envoyer([['reference' => 'PARA500', 'code_masante' => 'MED000001', 'prix_cfa' => 500]])
            ->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce qui est écrit : le serveur ne devine jamais
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function une_reference_inconnue_est_refusee_et_nommee_jamais_rapprochee(): void
    {
        // LE vecteur central. Se tromper de produit sur un stock enverrait un patient chercher
        // la mauvaise boîte : aucune ressemblance de libellé ne vaut équivalence.
        $reponse = $this->envoyer([['reference' => 'PARACETAMOL-500-CP', 'prix_cfa' => 500]]);

        $reponse->assertOk()->assertJsonPath('acceptees', 0)->assertJsonPath('refusees', 1);

        $refus = $reponse->json('refus.0');
        $this->assertSame('PARACETAMOL-500-CP', $refus['reference']);
        $this->assertStringContainsString('ne la devine pas', $refus['motif']);

        $this->assertDatabaseCount('prix_pharmacie', 0);
        $this->assertDatabaseCount('correspondances_partenaire', 0);
    }

    #[Test]
    public function le_partenaire_declare_l_equivalence_une_fois_et_n_a_plus_a_la_repeter(): void
    {
        // C'est ce qui rend vraie la promesse du §7.7 : « le pharmacien n'a rien à ressaisir ».
        $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 30, 'prix_cfa' => 500],
        ], horodatage: now()->timestamp)->assertJsonPath('acceptees', 1);

        $this->assertDatabaseHas('correspondances_partenaire', [
            'structure_id' => $this->pharmacie->id,
            'reference_externe' => 'PARA500',
            'code_masante' => 'MED000001',
        ]);

        // Second envoi SANS le code : la correspondance retenue suffit.
        $this->envoyer([
            ['reference' => 'PARA500', 'quantite' => 12, 'prix_cfa' => 520],
        ], horodatage: now()->addSecond()->timestamp)->assertJsonPath('acceptees', 1);

        $this->assertSame(2, PrixPharmacie::count());
    }

    #[Test]
    public function un_code_national_inexistant_est_refuse_sans_creer_de_correspondance(): void
    {
        $reponse = $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED999999', 'prix_cfa' => 500],
        ]);

        $reponse->assertJsonPath('refusees', 1);
        $this->assertStringContainsString('MED999999', $reponse->json('refus.0.motif'));
        $this->assertDatabaseCount('correspondances_partenaire', 0);
    }

    #[Test]
    public function le_releve_porte_sa_provenance_et_jamais_celle_du_portail(): void
    {
        // Un relevé ne doit jamais mentir sur d'où il vient (précédent `provenance` de P6.8d,
        // `source` de P7-C, `origine` de P10c-1).
        $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 30, 'prix_cfa' => 500],
        ])->assertOk();

        // La valeur est écrite EN DUR, pas lue depuis la constante qu'on éprouve : un vecteur
        // qui asserte `IngestionStockOfficine::SOURCE` prouve que la constante vaut elle-même,
        // et survit à sa modification. **Trouvé par la mutation p11**, huitième instance de la
        // famille « le vecteur prouve autre chose ».
        $this->assertDatabaseHas('prix_pharmacie', [
            'structure_id' => $this->pharmacie->id,
            'source' => 'logiciel_officine',
            'quantite' => 30,
            'disponible' => true,
        ]);

        // Et dans l'autre sens : la provenance ne doit pas se confondre avec la saisie au portail
        // ni avec le signalement d'un citoyen.
        foreach (['pharmacie_portail', 'crowdsource_patient', 'cename'] as $autreSource) {
            $this->assertDatabaseMissing('prix_pharmacie', ['source' => $autreSource]);
        }
    }

    #[Test]
    public function une_quantite_nulle_est_une_rupture_et_non_un_troisieme_etat(): void
    {
        $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 0],
        ])->assertJsonPath('acceptees', 1);

        $releve = PrixPharmacie::firstOrFail();
        $this->assertFalse((bool) $releve->disponible);
        $this->assertNull($releve->prix_cfa, 'Le rayon est vide : aucun prix ne se déclare.');
        $this->assertSame(0, (int) $releve->quantite);
    }

    #[Test]
    public function un_produit_declare_disponible_sans_prix_est_refuse(): void
    {
        $reponse = $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 5],
        ]);

        $reponse->assertJsonPath('refusees', 1);
        $this->assertStringContainsString('prix', $reponse->json('refus.0.motif'));
    }

    #[Test]
    public function les_bornes_de_plausibilite_du_service_existant_s_appliquent_a_l_ingestion(): void
    {
        // L'API n'est PAS un second chemin d'écriture (ADR-030) : elle passe par le service que
        // le pharmacien utilise au portail, donc elle hérite de ses gardes sans les réécrire.
        $reponse = $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 5, 'prix_cfa' => 9_000_000],
        ]);

        $reponse->assertJsonPath('refusees', 1);
        $this->assertDatabaseCount('prix_pharmacie', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce qui est dit de ce qui a échoué
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function un_lot_partiellement_fautif_ecrit_le_reste_et_nomme_les_refus(): void
    {
        // Perdre 2 lignes valides à cause d'une fautive rendrait l'intégration inutilisable ;
        // accepter en silence la rendrait indigne de confiance.
        $reponse = $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 30, 'prix_cfa' => 500],
            ['reference' => 'INCONNUE-1', 'quantite' => 5, 'prix_cfa' => 300],
            ['reference' => 'PARA500', 'quantite' => 10, 'prix_cfa' => 510],
        ]);

        $reponse->assertOk()
            ->assertJsonPath('recues', 3)
            ->assertJsonPath('acceptees', 2)
            ->assertJsonPath('refusees', 1)
            ->assertJsonPath('refus.0.index', 1)
            ->assertJsonPath('refus.0.reference', 'INCONNUE-1');

        $this->assertSame(2, PrixPharmacie::count());
    }

    #[Test]
    public function chaque_envoi_laisse_une_trace_avec_le_detail_de_ses_refus(): void
    {
        // *Une intégration qui échoue en silence est pire qu'une intégration qui échoue.*
        $this->envoyer([
            ['reference' => 'PARA500', 'code_masante' => 'MED000001', 'prix_cfa' => 500],
            ['reference' => 'INCONNUE-1'],
        ])->assertOk();

        $journal = JournalIngestion::firstOrFail();

        $this->assertSame($this->client->id, $journal->client_api_id);
        $this->assertSame(2, $journal->lignes_recues);
        $this->assertSame(1, $journal->lignes_acceptees);
        $this->assertCount(1, $journal->refus_json);
        $this->assertSame('INCONNUE-1', $journal->refus_json[0]['reference']);
    }

    #[Test]
    public function un_envoi_rejoue_avec_la_meme_cle_d_idempotence_n_ecrit_pas_deux_fois(): void
    {
        $lignes = [['reference' => 'PARA500', 'code_masante' => 'MED000001', 'quantite' => 30, 'prix_cfa' => 500]];

        $premier = $this->envoyer($lignes, horodatage: now()->timestamp, idempotencyKey: 'lot-2026-08-30-001');
        $premier->assertOk()->assertJsonPath('rejeu', false);

        // Signature différente (autre horodatage) mais MÊME clé d'idempotence : c'est le cas
        // réel du partenaire qui rejoue après un délai réseau, pas un rejeu d'attaquant.
        $second = $this->envoyer($lignes, horodatage: now()->addSeconds(2)->timestamp, idempotencyKey: 'lot-2026-08-30-001');
        $second->assertOk()->assertJsonPath('rejeu', true);

        $this->assertSame(1, PrixPharmacie::count(), 'Le rejeu ne doit pas écrire une seconde fois.');
        $this->assertSame(1, JournalIngestion::count());
    }

    #[Test]
    public function le_client_ne_peut_pas_declarer_son_identifiant_ni_son_secret(): void
    {
        // Ce que le serveur décide n'entre jamais par la porte du client (précédent `nis`,
        // `code`, `identifiant_national`). Vecteur sur le MODÈLE : un import n'aurait pas de
        // `validate()` devant lui (parade P6.6b).
        $client = new ClientApi([
            'structure_id' => $this->pharmacie->id,
            'libelle' => 'X',
            'domaines_json' => ['stock_officine'],
            'identifiant' => 'API-CHOISI-PAR-LE-CLIENT',
            'secret_chiffre' => 'secret-choisi',
        ]);

        $this->assertNull($client->identifiant);
        $this->assertNull($client->secret_chiffre);
    }

    #[Test]
    public function la_correspondance_declaree_est_unique_par_etablissement_et_reference(): void
    {
        // Sans cette unicité, deux déclarations contradictoires coexisteraient et la résolution
        // dépendrait de l'ordre d'insertion.
        CorrespondancePartenaire::create([
            'structure_id' => $this->pharmacie->id, 'domaine' => 'medicament',
            'reference_externe' => 'PARA500', 'code_masante' => 'MED000001',
        ]);

        $this->expectException(QueryException::class);

        CorrespondancePartenaire::create([
            'structure_id' => $this->pharmacie->id, 'domaine' => 'medicament',
            'reference_externe' => 'PARA500', 'code_masante' => 'MED000002',
        ]);
    }
}
