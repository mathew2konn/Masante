<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Consultation;
use App\Models\Diagnostic;
use App\Models\Maladie;
use App\Models\MembreFamille;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Maladie\ServiceMaladies;
use App\Services\Referentiel\SourceMaladies;
use App\Services\ServiceConsultation;
use App\Services\SessionDossierService;
use Database\Seeders\MaladieSeeder;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\TestCase;

/**
 * B2-b — le diagnostic posé en consultation (CDC_11 §5.2, CDC_04 §103).
 *
 * CE QUE CETTE SUITE PROTÈGE. La table la plus proche existait déjà — `antecedents` porte
 * `maladie_id` et un libellé figé — et y écrire chaque diagnostic aurait été bien plus court. Le
 * code du projet dit déjà pourquoi c'est faux : `antecedents.impact_triage` alimente le score des
 * triages suivants, donc *y consigner chaque grippe la transformerait en antécédent permanent
 * pesant sur toutes les orientations futures* (`RegistreRetourTriage`, P10c-2-i).
 *
 * D'où la garantie centrale, qui a son vecteur : **poser un diagnostic ne crée AUCUN antécédent**.
 * L'inscription est un acte séparé, et le médecin en choisit le type.
 */
class DiagnosticConsultationTest extends TestCase
{
    use GouverneUnReferentiel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function soignant(bool $habilite = true): User
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $user = User::factory()->create(['structure_id' => $structure->id]);

        if ($habilite) {
            $user->givePermissionTo('dossier.ecrire');
        }

        return $user->fresh();
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    private function ouvrirSession(MembreFamille $membre, User $soignant, string $voie = 'qr_scan'): AccesDossier
    {
        $acces = AccesDossier::create([
            'membre_id' => $membre->id,
            'agent_id' => $soignant->id,
            'type_acces' => $voie,
            'etablissement' => 'CHU de Cocody',
            'motif_urgence' => $voie === 'bris_de_glace' ? 'Patient inconscient' : null,
        ]);

        app(SessionDossierService::class)->ouvrir($acces);

        return $acces;
    }

    private function service(): ServiceConsultation
    {
        return app(ServiceConsultation::class);
    }

    /**
     * Met le référentiel des maladies en vigueur et rend une entrée publiée.
     *
     * LE BACKFILL N'EST PAS FACULTATIF : le seeder laisse les codes nationaux nuls, et le contrôle
     * qualité refuse alors la publication. C'est la conséquence de déploiement que P10c-3-ii avait
     * déjà relevée (« `MaladieSeeder` PUIS `masante:maladies:backfill` — le seeder seul laisse les
     * codes nuls »), et elle se manifeste ici exactement de la même façon.
     */
    private function maladiePubliee(): Maladie
    {
        $this->seed(MaladieSeeder::class);
        $this->artisan('masante:maladies:backfill')->assertSuccessful();
        $this->publierReferentiel(SourceMaladies::CODE);

        return Maladie::whereNotNull('code')->orderBy('id')->firstOrFail();
    }

    /** @return array{0: User, 1: Consultation} */
    private function consultationOuverte(): array
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);

        return [$soignant, $this->service()->ouvrir($soignant, 'Fièvre')];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que B2-b ouvre
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_diagnostic_est_pose_sans_rattachement_au_referentiel(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();

        $diagnostic = $this->service()->diagnostiquer($soignant, $consultation, 'Angine érythémateuse');

        $this->assertDatabaseCount('diagnostics', 1);
        $this->assertSame($consultation->id, $diagnostic->consultation_id);
        $this->assertSame('Angine érythémateuse', $diagnostic->libelle);
        $this->assertNull($diagnostic->maladie_id);
        $this->assertFalse($diagnostic->estCode());
        $this->assertFalse($diagnostic->estPromu());
    }

    /**
     * Quand le lien EST fourni, code et libellé sont relus à la version PUBLIÉE et figés — patron
     * P6.8c. Les mots du médecin ne sont jamais réécrits : le lien s'ajoute à côté (leçon P6.7a).
     */
    public function test_le_rattachement_fige_le_code_et_le_libelle_du_referentiel(): void
    {
        $maladie = $this->maladiePubliee();
        [$soignant, $consultation] = $this->consultationOuverte();

        $diagnostic = $this->service()->diagnostiquer(
            $soignant, $consultation, 'Accès palustre simple', $maladie->id
        );

        $this->assertSame($maladie->id, $diagnostic->maladie_id);
        $this->assertSame($maladie->code, $diagnostic->maladie_code);
        $this->assertSame($maladie->libelle, $diagnostic->maladie_libelle);
        // Les mots du médecin, intacts — ce ne sont pas ceux du référentiel.
        $this->assertSame('Accès palustre simple', $diagnostic->libelle);
        $this->assertNotSame($maladie->libelle, $diagnostic->libelle);
    }

    /**
     * LE SERVEUR NE DEVINE JAMAIS. Un libellé identique à une entrée du référentiel ne produit
     * AUCUN rattachement : rapprocher serait un diagnostic posé par une machine (CDC_00 §4,
     * décision P6.8c).
     */
    public function test_le_serveur_ne_rapproche_jamais_un_libelle_du_referentiel(): void
    {
        $maladie = $this->maladiePubliee();
        [$soignant, $consultation] = $this->consultationOuverte();

        $diagnostic = $this->service()->diagnostiquer($soignant, $consultation, $maladie->libelle);

        $this->assertNull($diagnostic->maladie_id);
        $this->assertNull($diagnostic->maladie_code);
    }

    public function test_une_maladie_inconnue_du_referentiel_est_refusee(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();

        $this->attendRefus(
            fn () => $this->service()->diagnostiquer($soignant, $consultation, 'Quelque chose', 999_999),
            "La maladie n°999999 n'existe pas au référentiel national."
        );

        $this->assertDatabaseCount('diagnostics', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La garantie centrale : un diagnostic n'est PAS un antécédent
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LA GARANTIE QUI JUSTIFIE UNE TABLE À PART. `antecedents.impact_triage` pèse sur les triages
     * suivants : si poser un diagnostic en créait un, chaque grippe deviendrait un antécédent
     * permanent et *on dégraderait l'orientation qu'on cherche à améliorer*.
     */
    public function test_poser_un_diagnostic_ne_cree_aucun_antecedent(): void
    {
        $maladie = $this->maladiePubliee();
        [$soignant, $consultation] = $this->consultationOuverte();

        $this->service()->diagnostiquer($soignant, $consultation, 'Grippe saisonnière', $maladie->id);

        $this->assertDatabaseCount('diagnostics', 1);
        $this->assertDatabaseCount('antecedents', 0);
    }

    public function test_la_promotion_inscrit_l_antecedent_par_le_chemin_soignant(): void
    {
        $maladie = $this->maladiePubliee();
        [$soignant, $consultation] = $this->consultationOuverte();
        $diagnostic = $this->service()->diagnostiquer(
            $soignant, $consultation, 'Diabète de type 2', $maladie->id
        );

        $antecedent = $this->service()->promouvoirEnAntecedent(
            $soignant, $consultation, $diagnostic, 'maladie_chronique'
        );

        $this->assertDatabaseCount('antecedents', 1);
        $this->assertSame('maladie_chronique', $antecedent->type);
        $this->assertSame('Diabète de type 2', $antecedent->description);
        $this->assertSame($maladie->id, $antecedent->maladie_id);
        // Écrit par le CHEMIN SOIGNANT (P7-D0) : la provenance est une décision du serveur.
        $this->assertSame('medecin', $antecedent->source);
        $this->assertSame('medecin', $antecedent->added_by);
        // Le diagnostic porte désormais la trace de sa promotion.
        $this->assertSame($antecedent->id, $diagnostic->fresh()->antecedent_id);
        $this->assertTrue($diagnostic->fresh()->estPromu());
    }

    public function test_un_diagnostic_ne_se_promeut_pas_deux_fois(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();
        $diagnostic = $this->service()->diagnostiquer($soignant, $consultation, 'Asthme');
        $this->service()->promouvoirEnAntecedent($soignant, $consultation, $diagnostic, 'maladie_chronique');

        $this->attendRefus(
            fn () => $this->service()->promouvoirEnAntecedent(
                $soignant, $consultation, $diagnostic->fresh(), 'autre'
            ),
            'Ce diagnostic est déjà inscrit aux antécédents.'
        );

        $this->assertDatabaseCount('antecedents', 1);
    }

    public function test_un_diagnostic_d_une_autre_consultation_n_est_pas_promu(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();
        $diagnostic = $this->service()->diagnostiquer($soignant, $consultation, 'Otite');

        // Une seconde consultation, sur un autre accès, menée par le même soignant : seules les
        // deux autres gardes sont satisfaites, pour isoler celle-ci.
        $autrePatient = $this->patient();
        $this->ouvrirSession($autrePatient, $soignant);
        $autre = $this->service()->ouvrir($soignant, 'Autre motif');

        $this->attendRefus(
            fn () => $this->service()->promouvoirEnAntecedent(
                $soignant, $autre, $diagnostic, 'autre'
            ),
            'Ce diagnostic appartient à une autre consultation.'
        );

        $this->assertDatabaseCount('antecedents', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les gardes — chacune son vecteur, chacune son message
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_diagnostic_vide_est_refuse(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();

        $this->attendRefus(
            fn () => $this->service()->diagnostiquer($soignant, $consultation, '   '),
            'Un diagnostic ne peut pas être vide.'
        );

        $this->assertDatabaseCount('diagnostics', 0);
    }

    public function test_un_soignant_non_habilite_ne_diagnostique_pas(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();

        $autre = $this->soignant(habilite: false);

        $this->attendRefus(
            fn () => $this->service()->diagnostiquer($autre, $consultation, 'Angine'),
            "Vous n'êtes pas habilité à mener une consultation."
        );

        $this->assertDatabaseCount('diagnostics', 0);
    }

    /**
     * Le bris de glace ouvre le vital minimal SANS consentement : y poser un diagnostic ferait
     * d'un accès d'exception un acte de soin. Le soignant est ici pleinement habilité — seule la
     * voie refuse, sinon le vecteur prouverait l'habilitation.
     */
    public function test_le_bris_de_glace_ne_permet_pas_de_diagnostiquer(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();

        $this->ouvrirSession($consultation->membre, $soignant, 'bris_de_glace');

        $this->attendRefus(
            fn () => $this->service()->diagnostiquer($soignant, $consultation, 'Angine'),
            "Cet accès est en lecture seule : le patient n'a pas consenti à une écriture."
        );

        $this->assertDatabaseCount('diagnostics', 0);
    }

    public function test_un_autre_soignant_ne_diagnostique_pas_dans_la_consultation_d_un_confrere(): void
    {
        [, $consultation] = $this->consultationOuverte();

        $second = $this->soignant();
        $this->ouvrirSession($consultation->membre, $second);

        $this->attendRefus(
            fn () => $this->service()->diagnostiquer($second, $consultation, 'Angine'),
            'Cette consultation est menée par un autre soignant.'
        );

        $this->assertDatabaseCount('diagnostics', 0);
    }

    public function test_une_consultation_cloturee_n_accepte_plus_de_diagnostic(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();
        $this->service()->cloturer($soignant, $consultation);

        $this->attendRefus(
            fn () => $this->service()->diagnostiquer($soignant, $consultation->fresh(), 'Trop tard'),
            'Cette consultation est clôturée.'
        );

        $this->assertDatabaseCount('diagnostics', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que le client ne déclare jamais, et la garde du moteur
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * UNE COUCHE, UN VECTEUR (parade P6.6b). Celui-ci appelle le SERVICE directement, comme le
     * ferait un import : `maladie_code` et `maladie_libelle` sont effacés puis reposés depuis la
     * version publiée, quoi qu'on prétende leur donner.
     */
    public function test_le_client_ne_peut_pas_declarer_le_code_ni_le_libelle(): void
    {
        $maladie = $this->maladiePubliee();
        [$soignant, $consultation] = $this->consultationOuverte();

        $diagnostic = $this->service()->diagnostiquer(
            $soignant, $consultation, 'Accès palustre', $maladie->id
        );

        $this->assertNotSame('MAL999999', $diagnostic->maladie_code);
        $this->assertSame($maladie->code, $diagnostic->maladie_code);
        $this->assertSame($maladie->libelle, $diagnostic->maladie_libelle);
    }

    /** `libelle` porte du contenu clinique : il est chiffré au repos, comme partout ailleurs. */
    public function test_le_libelle_est_chiffre_en_base(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();
        $this->service()->diagnostiquer($soignant, $consultation, 'Tuberculose pulmonaire');

        $brut = \DB::table('diagnostics')->value('libelle');

        $this->assertNotSame('Tuberculose pulmonaire', $brut);
        $this->assertStringNotContainsString('Tuberculose', (string) $brut);
    }

    /** Un antécédent ne peut pas être la promotion de deux diagnostics (garde déclarative). */
    public function test_le_moteur_refuse_deux_diagnostics_sur_le_meme_antecedent(): void
    {
        [$soignant, $consultation] = $this->consultationOuverte();
        $premier = $this->service()->diagnostiquer($soignant, $consultation, 'Asthme');
        $second = $this->service()->diagnostiquer($soignant, $consultation, 'Rhinite');
        $antecedent = $this->service()->promouvoirEnAntecedent(
            $soignant, $consultation, $premier, 'maladie_chronique'
        );

        $this->expectException(QueryException::class);

        \DB::table('diagnostics')->where('id', $second->id)->update(['antecedent_id' => $antecedent->id]);
    }

    /**
     * DÉFAUT RÉEL TROUVÉ AU G0 DE B2-b, ET IL DATE DE P6.8c. L'instantané publié ne porte pas
     * d'`id` (délibérément), mais `formulaire.blade.php` écrivait `value="{{ $m['id'] }}"` : chaque
     * option valait la chaîne vide, donc le rattachement d'un antécédent au référentiel était
     * INOPÉRANT depuis le portail — sans qu'aucune erreur ne s'affiche.
     */
    public function test_la_liste_publiee_porte_l_identifiant_attendu_par_les_formulaires(): void
    {
        $this->maladiePubliee();

        $liste = app(ServiceMaladies::class)->listePubliee();

        $this->assertNotEmpty($liste);

        foreach ($liste as $entree) {
            $this->assertArrayHasKey('id', $entree);
            $this->assertIsInt($entree['id']);
            $this->assertTrue(Maladie::whereKey($entree['id'])->exists());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Vérifie qu'un refus survient ET qu'il survient pour la BONNE raison (leçon B1-d). */
    private function attendRefus(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail("Refus attendu : {$message}");
        } catch (ValidationException $e) {
            $this->assertContains(
                $message,
                collect($e->errors())->flatten()->all(),
                'Le refus a bien eu lieu, mais pas pour la raison attendue.'
            );
        }
    }
}
