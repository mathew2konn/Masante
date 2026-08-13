<?php

namespace Tests\Feature;

use App\Models\ExerciceProfessionnel;
use App\Models\Medecin;
use App\Models\Referentiel;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Etablissement\AttributeurIdentifiantEtablissement;
use App\Services\Professionnel\AttributeurNumeroProfessionnel;
use App\Services\Professionnel\GenerateurNumeroProfessionnel;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Services\Referentiel\SourceProfessionnels;
use App\Support\ProfessionsSante;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6.5a — Référentiel national des professionnels de santé (CDC_09 §5.1/§5.2).
 *
 * CE QUE CETTE SUITE PROTÈGE EN PRIORITÉ, dans l'ordre :
 *
 * 1. LA PROJECTION SÉPARE L'IDENTITÉ DE LA VITRINE. Deux vecteurs en miroir, et aucun ne suffit
 *    seul : changer le TARIF de consultation ne doit PAS faire diverger le référentiel publié ;
 *    changer le NUMÉRO D'ORDRE doit le faire. Un référentiel insensible à tout serait inutile ;
 *    sensible à tout, il divergerait à chaque retouche de biographie.
 *
 * 2. L'ÉTABLISSEMENT NE DÉCLARE PAS LE DROIT D'EXERCER DE SES PROPRES MÉDECINS. C'est la garde la
 *    plus importante de l'incrément : ces colonnes sont celles dont dépendra le §5.4 avant de
 *    laisser signer une ordonnance. Si l'employeur pouvait les écrire, le contrôle reposerait sur
 *    la déclaration de l'intéressé.
 *
 * 3. LA REDONDANCE ASSUMÉE NE DÉRIVE PAS. `medecins.structure_id` (lu par P3/P4, validés G5) et
 *    `professionnel_etablissement` disent la même chose de deux façons ; le contrôle qualité doit
 *    hurler si elles cessent de concorder.
 *
 * Écrite dans les deux sens : chaque contrôle a son vecteur qui échoue ET le contenu sain qui n'en
 * déclenche aucun.
 */
class ReferentielProfessionnelsTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = SourceProfessionnels::CODE;

    private StructureSanitaire $structure;

    private ServiceEtablissement $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);

        $this->structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Boulevard de France',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        app(AttributeurIdentifiantEtablissement::class)->attribuer($this->structure);
        $this->structure->refresh();

        $this->service = ServiceEtablissement::create([
            'structure_id' => $this->structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    /** Un professionnel complet et cohérent — le contenu « sain » de référence. */
    private function professionnel(array $remplacements = [], bool $avecExercice = true): Medecin
    {
        $professionnel = Medecin::create(array_merge([
            'structure_id'            => $this->structure->id,
            'service_id'              => $this->service->id,
            'titre'                   => 'Dr',
            'prenom'                  => 'Aya',
            'nom'                     => 'Koffi',
            'sexe'                    => 'F',
            'specialite'              => 'Cardiologie',
            'profession'              => 'medecin_specialiste',
            'ordre_professionnel'     => 'Ordre National des Médecins de Côte d\'Ivoire',
            'numero_ordre'            => 'ONM-4412',
            'autorisation_numero'     => 'AUT-2024-118',
            'autorisation_statut'     => 'valide',
            'autorisation_delivree_le' => '2024-01-15',
            'autorisation_expire_le'  => '2030-01-15',
            'universite'              => 'Université Félix Houphouët-Boigny',
            'annee_diplome'           => 2012,
            'tarif_consultation'      => 15000,
            'actif'                   => true,
        ], $remplacements));

        app(AttributeurNumeroProfessionnel::class)->attribuer($professionnel);

        if ($avecExercice) {
            ExerciceProfessionnel::create([
                'medecin_id'    => $professionnel->id,
                'structure_id'  => $this->structure->id,
                'service_id'    => $this->service->id,
                'est_principal' => true,
                'actif'         => true,
            ]);
        }

        return $professionnel->fresh();
    }

    private function source(): SourceProfessionnels
    {
        return app(SourceProfessionnels::class);
    }

    private function agent(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /** Enregistre puis publie le référentiel des professionnels. */
    private function publier(): Referentiel
    {
        $gouvernance = app(ServiceGouvernanceReferentiel::class);
        $gouvernance->enregistrer(self::CODE);

        $gouvernance->proposer(
            self::CODE, 'CI',
            $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER),
            'Première mise en vigueur du référentiel des professionnels.',
        );
        $gouvernance->publier(
            self::CODE, 'CI',
            $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER),
            'Contrôles conformes, publication nationale.',
        );

        return Referentiel::where('code', self::CODE)->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Numéro national (§5.2)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_numero_suit_la_forme_pro_plus_six_chiffres(): void
    {
        $professionnel = $this->professionnel();

        $this->assertSame('PRO000001', $professionnel->numero_professionnel);
        $this->assertTrue(GenerateurNumeroProfessionnel::formeValide($professionnel->numero_professionnel));
    }

    public function test_la_forme_valide_refuse_ce_qui_n_en_est_pas(): void
    {
        // Contrôle de FORME seulement : sans clé, il n'y a rien de plus à vérifier, et un
        // `PRO999999` bien formé peut n'avoir jamais été attribué.
        $this->assertTrue(GenerateurNumeroProfessionnel::formeValide('PRO999999'));
        $this->assertFalse(GenerateurNumeroProfessionnel::formeValide('PRO00001'));
        $this->assertFalse(GenerateurNumeroProfessionnel::formeValide('ETS000001'));
        $this->assertFalse(GenerateurNumeroProfessionnel::formeValide('pro000001'));
    }

    public function test_l_attribution_est_idempotente_et_ne_consomme_pas_la_sequence(): void
    {
        $professionnel = $this->professionnel();
        $attributeur = app(AttributeurNumeroProfessionnel::class);

        $rejeu = $attributeur->attribuer($professionnel);

        $this->assertSame('PRO000001', $rejeu);
        $this->assertSame(1, (int) DB::table('professionnel_compteurs')->where('pays_code', 'CI')->value('dernier'));
    }

    public function test_deux_pays_peuvent_partager_le_meme_numero(): void
    {
        $ivoirien = $this->professionnel();

        $senegalais = Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'prenom' => 'Moussa', 'nom' => 'Diop', 'specialite' => 'Pédiatrie',
            'actif' => true,
        ]);
        $senegalais->forceFill(['pays_code' => 'SN'])->save();
        app(AttributeurNumeroProfessionnel::class)->attribuer($senegalais);

        // Le pays QUALIFIE le numéro, il ne s'écrit pas dedans (P6.4a). L'unicité porte sur le
        // couple : un ordre professionnel s'arrête à sa frontière.
        $this->assertSame('PRO000001', $ivoirien->numero_professionnel);
        $this->assertSame('PRO000001', $senegalais->fresh()->numero_professionnel);
    }

    public function test_le_numero_national_ne_peut_pas_etre_choisi_par_le_client(): void
    {
        // Hors `$fillable`, comme `identifiant_national` en P6.4a : un client ne choisit pas son
        // identifiant national, il le reçoit.
        $professionnel = Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'prenom' => 'Ines', 'nom' => 'Bamba', 'specialite' => 'ORL',
            'actif' => true,
            'numero_professionnel' => 'PRO999999',
            'pays_code' => 'ZZ',
        ]);

        $this->assertNull($professionnel->fresh()->numero_professionnel);
        $this->assertSame('CI', $professionnel->fresh()->pays_code);
    }

    public function test_l_unicite_du_numero_est_garantie_par_le_moteur(): void
    {
        $this->professionnel();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('medecins')->insert([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'prenom' => 'Doublon', 'nom' => 'Interdit', 'specialite' => 'X',
            'numero_professionnel' => 'PRO000001', 'pays_code' => 'CI',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Garde anti-divergence : migration ↔ source unique
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_enumeration_de_la_base_et_la_source_unique_ne_divergent_pas(): void
    {
        // La migration écrit sa liste en toutes lettres (un enregistrement historique ne doit pas
        // changer de sens quand la classe applicative évolue). Ce test est le prix de cette
        // duplication : sans lui, ajouter un métier dans `ProfessionsSante` sans migration
        // produirait un champ que le formulaire propose et que la base refuse.
        foreach (ProfessionsSante::codes() as $code) {
            $professionnel = $this->professionnel(['profession' => $code], avecExercice: false);
            $this->assertSame($code, $professionnel->profession);
        }
    }

    public function test_la_profession_n_est_pas_la_specialite(): void
    {
        $professionnel = $this->professionnel([
            'profession' => 'medecin_specialiste',
            'specialite' => 'Cardiologie',
        ]);

        // Deux axes distincts : un radiologue et un cardiologue sont tous deux médecins
        // spécialistes. Les fondre rendrait insoluble « combien de sages-femmes ici ? » (§4.4).
        $this->assertSame('Médecin spécialiste', ProfessionsSante::libelle($professionnel->profession));
        $this->assertSame('Cardiologie', $professionnel->specialite);
    }

    public function test_les_professions_prescriptrices_sont_une_donnee_pas_un_if(): void
    {
        $this->assertTrue(ProfessionsSante::peutPrescrire('medecin_generaliste'));
        $this->assertTrue(ProfessionsSante::peutPrescrire('sage_femme'));
        $this->assertFalse(ProfessionsSante::peutPrescrire('kinesitherapeute'));
        $this->assertFalse(ProfessionsSante::peutPrescrire(null));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La projection gouvernée — les deux vecteurs en miroir
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_projection_porte_l_identite_et_pas_la_vitrine(): void
    {
        $this->professionnel();
        $ligne = $this->source()->extraire()[0];

        foreach (['numero_professionnel', 'profession', 'ordre_professionnel', 'numero_ordre',
            'autorisation_statut', 'autorisation_expire_le', 'exercices'] as $attendu) {
            $this->assertArrayHasKey($attendu, $ligne);
        }

        // Ce qui doit en être ABSENT : tout ce qui n'engage aucune autorité et se corrige au fil
        // de l'eau. L'y mettre transformerait la correction d'un tarif en décision nationale.
        foreach (['tarif_consultation', 'biographie', 'telephone', 'email', 'langues_json',
            'consultation_en_ligne', 'sous_specialite'] as $exclu) {
            $this->assertArrayNotHasKey($exclu, $ligne, "« {$exclu} » ne doit pas entrer dans le référentiel.");
        }
    }

    public function test_un_changement_de_tarif_ne_fait_pas_diverger_le_referentiel(): void
    {
        $professionnel = $this->professionnel();
        $publiee = $this->publier()->versionPubliee();

        Medecin::whereKey($professionnel->id)->update([
            'tarif_consultation' => 25000,
            'biographie'         => 'Vingt ans de pratique hospitalière.',
            'telephone'          => '+2250101020304',
        ]);

        $this->assertTrue(
            hash_equals($publiee->empreinte, EmpreinteReferentiel::duContenu($this->source()->extraire())),
            'Un changement de tarif a fait diverger le référentiel national : la projection ne '
            .'sépare pas l\'identité professionnelle de la vitrine.',
        );
    }

    public function test_un_changement_de_numero_d_ordre_fait_diverger_le_referentiel(): void
    {
        $professionnel = $this->professionnel();
        $publiee = $this->publier()->versionPubliee();

        // Le miroir du vecteur précédent : la projection doit rester SENSIBLE à ce qui engage une
        // autorité. Insensible à tout, elle ne servirait à rien.
        Medecin::whereKey($professionnel->id)->update(['numero_ordre' => 'ONM-9999']);

        $this->assertFalse(
            hash_equals($publiee->empreinte, EmpreinteReferentiel::duContenu($this->source()->extraire())),
            "Un changement de numéro d'ordre doit faire diverger le référentiel.",
        );
    }

    public function test_un_retrait_d_autorisation_fait_diverger_le_referentiel(): void
    {
        $professionnel = $this->professionnel();
        $publiee = $this->publier()->versionPubliee();

        Medecin::whereKey($professionnel->id)->update(['autorisation_statut' => 'retiree']);

        $this->assertFalse(
            hash_equals($publiee->empreinte, EmpreinteReferentiel::duContenu($this->source()->extraire())),
            "Le retrait d'une autorisation d'exercer doit faire diverger le référentiel : c'est "
            .'exactement le fait qu\'un référentiel national doit publier.',
        );
    }

    public function test_les_exercices_sont_projetes_par_identifiant_national(): void
    {
        $this->professionnel();
        $ligne = $this->source()->extraire()[0];

        // Par `ETS…` et non par `structure_id` : une clé primaire technique n'a de sens que dans
        // cette base, or un référentiel national publié doit pouvoir être lu ailleurs.
        $this->assertSame(
            [['etablissement' => $this->structure->identifiant_national, 'principal' => true, 'actif' => true]],
            $ligne['exercices'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Contrôles qualité (§10) — chacun a son vecteur, et le contenu sain n'en déclenche aucun
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_contenu_sain_ne_declenche_aucune_anomalie(): void
    {
        $this->professionnel();

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_un_referentiel_vide_est_refuse(): void
    {
        $this->assertNotEmpty($this->source()->controlerQualite([]));
    }

    public function test_un_professionnel_sans_numero_est_signale(): void
    {
        $this->professionnel();
        Medecin::query()->update(['numero_professionnel' => null]);

        $this->assertStringContainsString(
            'aucun numéro professionnel',
            implode(' | ', $this->source()->controlerQualite($this->source()->extraire())),
        );
    }

    public function test_un_professionnel_sans_autorisation_est_signale(): void
    {
        $this->professionnel(['autorisation_statut' => null]);

        // Un professionnel sans autorisation enregistrée n'est pas « probablement autorisé » :
        // c'est un professionnel dont nul ne sait s'il a le droit d'exercer.
        $this->assertStringContainsString(
            "aucune autorisation d'exercer",
            implode(' | ', $this->source()->controlerQualite($this->source()->extraire())),
        );
    }

    public function test_une_autorisation_delivree_apres_son_echeance_est_signalee(): void
    {
        $this->professionnel([
            'autorisation_delivree_le' => '2030-01-15',
            'autorisation_expire_le'   => '2024-01-15',
        ]);

        // Les deux dates sont plausibles prises séparément : c'est leur ORDRE qui est faux —
        // l'anomalie sournoise du couple région/district de P6.4a, transposée.
        $this->assertStringContainsString(
            'mais expirant le',
            implode(' | ', $this->source()->controlerQualite($this->source()->extraire())),
        );
    }

    public function test_une_autorisation_dite_valide_mais_expiree_est_signalee(): void
    {
        $this->professionnel([
            'autorisation_statut'      => 'valide',
            'autorisation_delivree_le' => '2015-01-15',
            'autorisation_expire_le'   => '2020-01-15',
        ]);

        $this->assertStringContainsString(
            "déclarée valide alors qu'elle a expiré",
            implode(' | ', $this->source()->controlerQualite($this->source()->extraire())),
        );
    }

    public function test_une_profession_absente_est_signalee(): void
    {
        $this->professionnel(['profession' => null]);

        $this->assertStringContainsString(
            'profession absente',
            implode(' | ', $this->source()->controlerQualite($this->source()->extraire())),
        );
    }

    public function test_le_meme_numero_dans_deux_pays_n_est_pas_un_doublon(): void
    {
        // DÉFAUT TROUVÉ AU G2 LIVE, PAS ICI — et c'est la raison d'être de ce vecteur.
        //
        // Le contrôle de doublon comparait les numéros SANS le pays. Il signalait donc
        // `PRO000001` comme dupliqué alors que l'index de la base autorise `CI-PRO000001` ET
        // `SN-PRO000001` : le contrôle était plus strict que le moteur, et il avait tort. Le
        // référentiel serait devenu impubliable dès le premier pays ajouté.
        $this->professionnel();

        $senegalais = Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'prenom' => 'Moussa', 'nom' => 'Diop', 'specialite' => 'Pédiatrie',
            'profession' => 'medecin_specialiste',
            'ordre_professionnel' => 'Ordre des Médecins du Sénégal', 'numero_ordre' => 'ONM-4412',
            'autorisation_numero' => 'SN-2024-1', 'autorisation_statut' => 'valide',
            'autorisation_delivree_le' => '2024-01-15', 'autorisation_expire_le' => '2030-01-15',
            'actif' => true,
        ]);
        $senegalais->forceFill(['pays_code' => 'SN'])->save();
        app(AttributeurNumeroProfessionnel::class)->attribuer($senegalais);
        ExerciceProfessionnel::create([
            'medecin_id' => $senegalais->id, 'structure_id' => $this->structure->id,
            'est_principal' => true, 'actif' => true,
        ]);

        $this->assertSame('PRO000001', $senegalais->fresh()->numero_professionnel);

        // Ni le numéro national ni le numéro d'ordre — homonyme d'un ordre à l'autre — ne doivent
        // déclencher de doublon entre deux pays.
        $anomalies = implode(' | ', $this->source()->controlerQualite($this->source()->extraire()));

        $this->assertStringNotContainsString('Doublon', $anomalies);
    }

    public function test_un_numero_d_ordre_porte_deux_fois_est_signale(): void
    {
        $this->professionnel();
        $this->professionnel(['prenom' => 'Koffi', 'nom' => 'Yao', 'numero_ordre' => 'ONM-4412']);

        $this->assertStringContainsString(
            "numéro d'ordre ONM-4412 est porté deux fois",
            implode(' | ', $this->source()->controlerQualite($this->source()->extraire())),
        );
    }

    public function test_une_annee_de_diplome_dans_le_futur_est_signalee(): void
    {
        $this->professionnel(['annee_diplome' => (int) now()->format('Y') + 5]);

        $this->assertStringContainsString(
            'année de diplôme dans le futur',
            implode(' | ', $this->source()->controlerQualite($this->source()->extraire())),
        );
    }

    public function test_un_exercice_principal_non_reporte_est_signale(): void
    {
        // LA GARDE DE LA REDONDANCE ASSUMÉE : `structure_id` et la table des exercices disent la
        // même chose de deux façons. Si elles cessent de concorder, le référentiel affirmerait
        // qu'un praticien exerce ailleurs que là où l'annuaire de P3/P4 le montre.
        $this->professionnel(avecExercice: false);

        $anomalies = implode(' | ', $this->source()->controlerQualite($this->source()->extraire()));

        $this->assertStringContainsString("n'apparaît pas dans ses lieux d'exercice", $anomalies);
        $this->assertStringContainsString("aucun lieu d'exercice", $anomalies);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Backfill
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_dry_run_n_ecrit_rien(): void
    {
        $professionnel = Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi', 'specialite' => 'Cardiologie',
            'actif' => true,
        ]);

        $this->artisan('masante:professionnels:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($professionnel->fresh()->numero_professionnel);
        $this->assertSame(0, ExerciceProfessionnel::count());
    }

    public function test_le_backfill_attribue_le_numero_et_reporte_l_exercice_principal(): void
    {
        $professionnel = Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi', 'specialite' => 'Cardiologie',
            'actif' => true,
        ]);

        $this->artisan('masante:professionnels:backfill')->assertSuccessful();

        $professionnel->refresh();
        $this->assertSame('PRO000001', $professionnel->numero_professionnel);

        $exercice = ExerciceProfessionnel::where('medecin_id', $professionnel->id)->sole();
        $this->assertSame($this->structure->id, $exercice->structure_id);
        $this->assertTrue($exercice->est_principal);
    }

    public function test_le_backfill_rejoue_ne_cree_rien(): void
    {
        $this->professionnel();

        $this->artisan('masante:professionnels:backfill')->assertSuccessful();
        $this->artisan('masante:professionnels:backfill')->assertSuccessful();

        $this->assertSame(1, Medecin::count());
        $this->assertSame(1, ExerciceProfessionnel::count());
        $this->assertSame(1, (int) DB::table('professionnel_compteurs')->where('pays_code', 'CI')->value('dernier'));
    }

    public function test_le_backfill_n_ecrase_pas_un_exercice_deja_decrit(): void
    {
        $professionnel = $this->professionnel(avecExercice: false);

        // Un gestionnaire a déjà décrit cet exercice à la main : le backfill n'a rien à lui
        // apprendre. `firstOrCreate` et non `updateOrCreate`, précisément pour cela.
        ExerciceProfessionnel::create([
            'medecin_id' => $professionnel->id, 'structure_id' => $this->structure->id,
            'est_principal' => true, 'actif' => true, 'debut_le' => '2019-09-01',
        ]);

        $this->artisan('masante:professionnels:backfill')->assertSuccessful();

        $this->assertSame('2019-09-01', ExerciceProfessionnel::sole()->debut_le->toDateString());
    }
}
