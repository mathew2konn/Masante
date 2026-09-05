<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\DemandeAnalyse;
use App\Models\JournalLaboratoire;
use App\Models\MembreFamille;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Analyse\GenerateurIdentifiantPrelevement;
use App\Services\Analyse\ReglesCode128;
use App\Services\Analyse\ServiceCircuitPrelevement;
use App\Support\StatutPrelevement;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * B5-b — le prélèvement, sans session de dossier (plan G1 PLAN 4, décision L3).
 *
 * CE QUE CETTE SUITE PROTÈGE :
 *
 *  1. Le vecteur central du lot est une ABSENCE : consulter une demande et enregistrer un
 *     prélèvement ne créent AUCUNE ligne dans `acces_dossier` (comme en B3-a).
 *  2. L'anti-IDOR est structurel : un laboratoire d'une autre structure reçoit 404, jamais 403,
 *     sur un prélèvement qui n'est pas le sien.
 *  3. Le cycle ne remonte jamais, et les quatre gardes du moteur tiennent même face à un accès SQL
 *     direct.
 *  4. `ReglesCode128` est prouvable par vecteurs : la clé de contrôle se recalcule à la main, et
 *     la table des motifs est interne-consistante (chaque symbole somme au nombre de modules
 *     qu'il doit occuper).
 */
class CircuitPrelevementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function laboratoire(string $nom = 'Laboratoire de test'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'laboratoire', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function laborantin(StructureSanitaire $labo): User
    {
        $user = User::factory()->create(['structure_id' => $labo->id]);
        $user->givePermissionTo('analyse.executer');

        return $user->fresh();
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    private function demande(): DemandeAnalyse
    {
        return $this->patient()->demandesAnalyses()->create([
            'medecin_nom' => 'Dr Test', 'structure_sanitaire' => 'Structure Test',
            'date_demande' => '2026-09-05',
            'analyses_json' => [['libelle' => 'Numération formule sanguine']],
        ]);
    }

    private function circuit(): ServiceCircuitPrelevement
    {
        return app(ServiceCircuitPrelevement::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // K1 — les tables existent
    // ─────────────────────────────────────────────────────────────────────────

    public function test_les_tables_du_circuit_existent(): void
    {
        $this->assertTrue(Schema::hasTable('prelevements'));
        $this->assertTrue(Schema::hasTable('journal_laboratoire'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L3 — le vecteur central : une ABSENCE
    // ─────────────────────────────────────────────────────────────────────────

    public function test_consulter_puis_enregistrer_ne_cree_aucune_ligne_dans_acces_dossier(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $demande = $this->demande();

        $this->assertSame(0, AccesDossier::count());

        $reconnue = $this->circuit()->demandePourJeton($demande->jeton_partage ?? $this->jetonBrut($demande));
        $this->circuit()->journaliserConsultation($laborantin, $reconnue);
        $this->circuit()->enregistrer($laborantin, $reconnue);

        $this->assertSame(0, AccesDossier::count(), 'Aucun accès au dossier ne doit jamais être créé.');
    }

    private function jetonBrut(DemandeAnalyse $demande): string
    {
        return DB::table('demandes_analyses')->where('id', $demande->id)->value('jeton_partage');
    }

    public function test_un_jeton_inconnu_ne_renvoie_rien(): void
    {
        $this->assertNull($this->circuit()->demandePourJeton('jeton-invente-au-hasard'));
        $this->assertNull($this->circuit()->demandePourJeton(null));
        $this->assertNull($this->circuit()->demandePourJeton(''));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enregistrement
    // ─────────────────────────────────────────────────────────────────────────

    public function test_enregistrer_pose_l_identifiant_le_laboratoire_et_le_statut(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $demande = $this->demande();

        $prelevement = $this->circuit()->enregistrer($laborantin, $demande);

        $this->assertNotNull($prelevement->identifiant);
        $this->assertStringStartsWith('PRE-', $prelevement->identifiant);
        $this->assertSame($labo->id, $prelevement->laboratoire_structure_id);
        $this->assertSame(StatutPrelevement::PRELEVE, $prelevement->statut);
        $this->assertNotNull($prelevement->preleve_le);
        $this->assertSame($laborantin->nomLisible(), $prelevement->preleve_par_nom);
    }

    public function test_enregistrer_ecrit_le_journal(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $demande = $this->demande();

        $this->circuit()->enregistrer($laborantin, $demande);

        $this->assertSame(1, JournalLaboratoire::where('action', 'prelevement_enregistre')->count());
        $entree = JournalLaboratoire::where('action', 'prelevement_enregistre')->first();
        $this->assertSame($demande->id, $entree->demande_id);
        $this->assertSame($laborantin->id, $entree->acteur_user_id);
        $this->assertSame($labo->id, $entree->laboratoire_structure_id);
    }

    public function test_un_laborantin_sans_habilitation_est_refuse(): void
    {
        $labo = $this->laboratoire();
        $sansPermission = User::factory()->create(['structure_id' => $labo->id]);
        $demande = $this->demande();

        $this->expectException(ValidationException::class);
        $this->circuit()->enregistrer($sansPermission, $demande);
    }

    public function test_un_compte_hors_laboratoire_est_refuse(): void
    {
        $pharmacie = StructureSanitaire::create([
            'nom' => 'Pharmacie', 'type' => 'pharmacie', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $agent = User::factory()->create(['structure_id' => $pharmacie->id]);
        $agent->givePermissionTo('analyse.executer');
        $demande = $this->demande();

        $this->expectException(ValidationException::class);
        $this->circuit()->enregistrer($agent->fresh(), $demande);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le cycle : quatre transitions, dans l'ordre, jamais en arrière
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_cycle_complet_avance_dans_l_ordre(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $prelevement = $this->circuit()->expedier($laborantin, $prelevement);
        $this->assertSame(StatutPrelevement::EXPEDIE, $prelevement->statut);
        $this->assertNotNull($prelevement->expedie_le);

        $prelevement = $this->circuit()->recevoir($laborantin, $prelevement);
        $this->assertSame(StatutPrelevement::RECU, $prelevement->statut);
        $this->assertNotNull($prelevement->recu_le);

        $prelevement = $this->circuit()->mettreEnAnalyse($laborantin, $prelevement);
        $this->assertSame(StatutPrelevement::EN_ANALYSE, $prelevement->statut);
        $this->assertNotNull($prelevement->analyse_le);
        $this->assertSame($laborantin->id, $prelevement->execute_par_user_id);
    }

    /** `expedie` est FACULTATIF (L6) : un prélèvement fait sur place saute directement à `recu`. */
    public function test_recevoir_directement_depuis_preleve_sans_expedition(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $prelevement = $this->circuit()->recevoir($laborantin, $prelevement);

        $this->assertSame(StatutPrelevement::RECU, $prelevement->statut);
        $this->assertNull($prelevement->expedie_le);
    }

    public function test_mettre_en_analyse_avant_reception_est_refuse(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $this->expectException(ValidationException::class);
        $this->circuit()->mettreEnAnalyse($laborantin, $prelevement);
    }

    public function test_chaque_transition_ecrit_le_journal(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $this->circuit()->expedier($laborantin, $prelevement);
        $this->circuit()->recevoir($laborantin, $prelevement->fresh());
        $this->circuit()->mettreEnAnalyse($laborantin, $prelevement->fresh());

        $this->assertSame(['prelevement_enregistre', 'expedie', 'recu', 'mis_en_analyse'],
            JournalLaboratoire::where('prelevement_id', $prelevement->id)->orderBy('id')->pluck('action')->all());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Anti-IDOR (404, jamais 403)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `abort_if(..., 404)` lève une `NotFoundHttpException` : prouvé en appel direct ici, ET en
     * HTTP réel juste après — l'appel direct seul ne prouverait pas que la route la traduit
     * effectivement en 404 pour un navigateur.
     */
    public function test_un_laboratoire_d_une_autre_structure_ne_peut_pas_transitionner(): void
    {
        $labo1 = $this->laboratoire('Laboratoire A');
        $labo2 = $this->laboratoire('Laboratoire B');
        $laborantin1 = $this->laborantin($labo1);
        $laborantin2 = $this->laborantin($labo2);

        $prelevement = $this->circuit()->enregistrer($laborantin1, $this->demande());

        $this->expectException(NotFoundHttpException::class);
        $this->circuit()->recevoir($laborantin2, $prelevement);
    }

    public function test_un_laboratoire_d_une_autre_structure_recoit_404_en_http(): void
    {
        $labo1 = $this->laboratoire('Laboratoire A');
        $labo2 = $this->laboratoire('Laboratoire B');
        $laborantin1 = $this->laborantin($labo1);
        $laborantin2 = $this->laborantin($labo2);

        $prelevement = $this->circuit()->enregistrer($laborantin1, $this->demande());

        $this->actingAs($laborantin2, 'web')
            ->get(route('portail.laboratoire.prelevement', $prelevement))
            ->assertNotFound();

        $this->actingAs($laborantin2, 'web')
            ->post(route('portail.laboratoire.recevoir', $prelevement))
            ->assertNotFound();
    }

    public function test_le_meme_laboratoire_peut_ouvrir_son_propre_prelevement_en_http(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $this->actingAs($laborantin, 'web')
            ->get(route('portail.laboratoire.prelevement', $prelevement))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les quatre gardes du moteur — même face à un accès SQL direct
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_moteur_refuse_un_identifiant_vide(): void
    {
        $labo = $this->laboratoire();
        $demande = $this->demande();

        $this->expectException(QueryException::class);
        DB::table('prelevements')->insert([
            'demande_id' => $demande->id, 'identifiant' => '',
            'laboratoire_structure_id' => $labo->id, 'statut' => 'preleve',
            'preleve_le' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_le_moteur_refuse_valide_sans_valideur_ni_date(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $this->expectException(QueryException::class);
        DB::table('prelevements')->where('id', $prelevement->id)->update(['statut' => 'valide']);
    }

    public function test_le_moteur_refuse_publie_sans_resultat_ni_date(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $this->expectException(QueryException::class);
        DB::table('prelevements')->where('id', $prelevement->id)->update(['statut' => 'publie']);
    }

    public function test_le_moteur_refuse_qu_un_etat_remonte(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());
        $this->circuit()->recevoir($laborantin, $prelevement);

        $this->expectException(QueryException::class);
        DB::table('prelevements')->where('id', $prelevement->id)->update(['statut' => 'preleve']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // journal_laboratoire — append-only
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_journal_refuse_la_modification_au_niveau_du_modele(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $this->circuit()->enregistrer($laborantin, $this->demande());

        $entree = JournalLaboratoire::first();

        $this->expectException(\RuntimeException::class);
        $entree->update(['action' => 'recu']);
    }

    public function test_le_journal_refuse_la_modification_au_niveau_du_moteur(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $this->circuit()->enregistrer($laborantin, $this->demande());

        $this->expectException(QueryException::class);
        DB::table('journal_laboratoire')->where('id', 1)->update(['action' => 'recu']);
    }

    public function test_le_journal_refuse_la_suppression_au_niveau_du_moteur(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $this->circuit()->enregistrer($laborantin, $this->demande());

        $this->expectException(QueryException::class);
        DB::table('journal_laboratoire')->where('id', 1)->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Correction B5-b : reprojeter une demande déjà prélevée est refusé
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_demande_prelevee_n_est_plus_reprojetee(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $demande = $this->demande();
        $this->circuit()->enregistrer($laborantin, $demande);

        $this->assertDatabaseCount('demande_analyse_lignes', 1);

        $demande->forceFill([
            'analyses_json' => [['libelle' => 'NFS'], ['libelle' => 'CRP'], ['libelle' => 'Ionogramme']],
        ])->save();

        $this->assertDatabaseCount('demande_analyse_lignes', 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GenerateurIdentifiantPrelevement
    // ─────────────────────────────────────────────────────────────────────────

    public function test_l_identifiant_genere_est_opaque_et_prefixe(): void
    {
        $identifiant = app(GenerateurIdentifiantPrelevement::class)->generer();

        $this->assertStringStartsWith('PRE-', $identifiant);
        $this->assertSame(14, strlen($identifiant));
    }

    public function test_deux_identifiants_generes_ne_se_repetent_pas(): void
    {
        $generateur = app(GenerateurIdentifiantPrelevement::class);
        $a = $generateur->generer();
        $b = $generateur->generer();

        $this->assertNotSame($a, $b);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReglesCode128 — la clé de contrôle, prouvable à la main (L16)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_cle_de_controle_se_recalcule_a_la_main(): void
    {
        // "A" en Code Set B : ord('A')=65, valeur = 65-32 = 33. START B = 104.
        // Checksum = (104 + 1*33) mod 103 = 137 mod 103 = 34.
        $valeurs = ReglesCode128::valeurs('A');
        $this->assertSame([104, 33], $valeurs);
        $this->assertSame(34, ReglesCode128::cleDeControle($valeurs));
    }

    public function test_la_cle_de_controle_sur_deux_caracteres(): void
    {
        // "AB" : START=104, A=33 (position 1), B=34 (position 2).
        // Checksum = (104 + 1*33 + 2*34) mod 103 = (104+33+68) mod 103 = 205 mod 103 = 102
        // (103×1=103, 205-103=102 ; 103×2=206 dépasse 205).
        $valeurs = ReglesCode128::valeurs('AB');
        $this->assertSame([104, 33, 34], $valeurs);
        $this->assertSame(102, ReglesCode128::cleDeControle($valeurs));
    }

    public function test_un_caractere_hors_ascii_imprimable_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReglesCode128::valeurs("\x01");
    }

    public function test_un_identifiant_vide_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReglesCode128::valeurs('');
    }

    /**
     * AUTO-VÉRIFICATION DE LA TABLE (L16) : chaque symbole 0-105 doit sommer EXACTEMENT au nombre
     * de modules qu'un symbole Code 128 occupe (11), l'ARRÊT (106) à 13. Ce n'est pas une preuve
     * de fidélité à la norme ISO/IEC 15417 — seul un scanner matériel réel le prouverait, et c'est
     * dit comme limite — mais une erreur de transcription y échouerait presque toujours.
     */
    public function test_chaque_motif_somme_au_bon_nombre_de_modules(): void
    {
        for ($valeur = 0; $valeur <= 105; $valeur++) {
            $this->assertSame(
                11,
                array_sum(ReglesCode128::motif($valeur)),
                "Le motif de la valeur {$valeur} ne somme pas à 11 modules.",
            );
        }

        $this->assertSame(13, array_sum(ReglesCode128::motif(106)), 'Le motif ARRÊT doit sommer à 13 modules.');
    }

    /** Un décodeur ne pourrait pas trancher si deux valeurs partageaient le même motif. */
    public function test_aucun_motif_n_est_duplique(): void
    {
        $motifs = [];

        for ($valeur = 0; $valeur <= 106; $valeur++) {
            $motifs[] = implode(',', ReglesCode128::motif($valeur));
        }

        $this->assertSame(count($motifs), count(array_unique($motifs)), 'Deux valeurs partagent le même motif.');
    }

    public function test_le_svg_est_produit_et_contient_des_rectangles(): void
    {
        $svg = ReglesCode128::svg('PRE-ABCDEFGHIJ');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_une_valeur_de_symbole_inconnue_est_refusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReglesCode128::motif(999);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L'écran laboratoire — étiquette accessible seulement au bon laboratoire
    // ─────────────────────────────────────────────────────────────────────────

    public function test_l_etiquette_est_un_svg_reel_pour_le_bon_laboratoire(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande());

        $reponse = $this->actingAs($laborantin, 'web')
            ->get(route('portail.laboratoire.etiquette', $prelevement))
            ->assertOk();

        $this->assertStringContainsString('image/svg', $reponse->headers->get('Content-Type'));
    }

    public function test_l_etiquette_est_refusee_a_un_autre_laboratoire(): void
    {
        $labo1 = $this->laboratoire('Laboratoire A');
        $labo2 = $this->laboratoire('Laboratoire B');
        $laborantin1 = $this->laborantin($labo1);
        $laborantin2 = $this->laborantin($labo2);
        $prelevement = $this->circuit()->enregistrer($laborantin1, $this->demande());

        $this->actingAs($laborantin2, 'web')
            ->get(route('portail.laboratoire.etiquette', $prelevement))
            ->assertNotFound();
    }
}
