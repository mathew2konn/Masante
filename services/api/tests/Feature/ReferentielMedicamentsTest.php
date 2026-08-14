<?php

namespace Tests\Feature;

use App\Models\InteractionMedicamenteuse;
use App\Models\Medicament;
use App\Models\PrixPharmacie;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Medicament\AttributeurCodeMedicament;
use App\Services\Medicament\GenerateurCodeMedicament;
use App\Services\Medicament\ServiceInteractions;
use App\Services\PrixMedicamentService;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceMedicaments;
use App\Support\Medicaments;
use App\Support\RegistreReferentiels;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P6.6a — Référentiel national des médicaments (CDC_09 §6).
 *
 * Ce que ces vecteurs doivent tenir :
 *
 *  · le code national est LITTÉRAL (`MED` + 6 chiffres), attribué par le serveur, jamais choisi par
 *    un client, et deux pays peuvent partager le même ;
 *  · une interaction est une RELATION : elle ne se déclare qu'une fois, quel que soit le sens, et
 *    jamais d'un produit avec lui-même ;
 *  · la projection prend la ligne entière — d'où DEUX vecteurs en miroir, aucun ne suffisant seul :
 *    un relevé de prix en pharmacie ne fait PAS diverger le référentiel, un dosage corrigé SI ;
 *  · l'écriture du catalogue national est fermée à `medicament.manage` — la permission d'une
 *    officine sur SES prix n'est pas celle de l'autorité sanitaire sur le catalogue.
 */
class ReferentielMedicamentsTest extends TestCase
{
    use RefreshDatabase;

    private function medicament(array $remplacements = []): Medicament
    {
        return Medicament::create(array_merge([
            'nom_generique' => 'Paracétamol',
            'categorie'     => 'Analgésique',
        ], $remplacements));
    }

    private function source(): SourceMedicaments
    {
        return new SourceMedicaments();
    }

    private function interactions(): ServiceInteractions
    {
        return app(ServiceInteractions::class);
    }

    private function attribuerLesCodes(): void
    {
        $attributeur = app(AttributeurCodeMedicament::class);

        foreach (Medicament::orderBy('id')->get() as $medicament) {
            $attributeur->attribuer($medicament);
        }
    }

    /**
     * Un gestionnaire d'officine : il tient `medicament.manage` de son RÔLE, comme en production.
     *
     * On ne passe surtout pas par `admin_ivoirsante` : ce rôle reçoit toutes les permissions
     * (`syncPermissions(Permission::all())` au seeder, qui le dit lui-même), donc un vecteur bâti
     * sur lui aurait été vert quoi qu'il arrive — il n'aurait rien vérifié.
     */
    private function gestionnaireOfficine(): User
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole('gestionnaire_etablissement');

        return $user->fresh();
    }

    /** Un agent porteur de l'habilitation nationale, accordée nominativement (aucun rôle ne la porte). */
    private function agentReferentiel(): User
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->givePermissionTo('medicament.referentiel');

        return $user->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le code national
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_code_national_est_litteral_sans_cle_de_controle(): void
    {
        $medicament = $this->medicament();

        $code = app(AttributeurCodeMedicament::class)->attribuer($medicament);

        $this->assertSame('MED000001', $code);
        $this->assertTrue(GenerateurCodeMedicament::formeValide($code));

        // L'exemple imposé par le §6.3 doit être une valeur VALIDE de ce format : une clé de
        // contrôle le rendrait invalide et mettrait le corpus en défaut.
        $this->assertTrue(GenerateurCodeMedicament::formeValide('MED000458'));
    }

    public function test_attribuer_est_idempotent_et_ne_consomme_pas_la_sequence(): void
    {
        $medicament = $this->medicament();
        $attributeur = app(AttributeurCodeMedicament::class);

        $premier = $attributeur->attribuer($medicament);
        $second  = $attributeur->attribuer($medicament->fresh());

        $this->assertSame($premier, $second);
        $this->assertSame(1, (int) \DB::table('medicament_compteurs')->where('pays_code', 'CI')->value('dernier'));
    }

    public function test_deux_pays_peuvent_partager_le_meme_code(): void
    {
        $ci = $this->medicament(['nom_generique' => 'Paracétamol CI']);
        app(AttributeurCodeMedicament::class)->attribuer($ci);

        $sn = $this->medicament(['nom_generique' => 'Paracétamol SN']);
        $sn->forceFill(['pays_code' => 'SN'])->save();
        app(AttributeurCodeMedicament::class)->attribuer($sn->fresh());

        $this->assertSame('MED000001', $ci->fresh()->code);
        $this->assertSame('MED000001', $sn->fresh()->code);
        $this->assertSame('SN', $sn->fresh()->pays_code);
    }

    public function test_le_code_ne_peut_pas_etre_choisi_par_un_client(): void
    {
        // `code` et `pays_code` sont hors `$fillable` : l'assignation de masse les ignore.
        $medicament = Medicament::create([
            'nom_generique' => 'Ibuprofène',
            'categorie'     => 'Anti-inflammatoire',
            'code'          => 'MED999999',
            'pays_code'     => 'XX',
        ]);

        $this->assertNull($medicament->code);
        $this->assertSame('CI', $medicament->fresh()->pays_code);
    }

    public function test_la_commande_de_backfill_est_rejouable(): void
    {
        $this->medicament(['nom_generique' => 'A']);
        $this->medicament(['nom_generique' => 'B']);

        $this->artisan('masante:medicaments:backfill --dry-run')
            ->expectsOutputToContain('2 médicament(s) recevraient un code national')
            ->assertSuccessful();

        $this->assertSame(2, Medicament::whereNull('code')->count(), 'Le dry-run a écrit en base.');

        $this->artisan('masante:medicaments:backfill')->assertSuccessful();
        $this->assertSame(0, Medicament::whereNull('code')->count());

        $codes = Medicament::orderBy('id')->pluck('code')->all();

        $this->artisan('masante:medicaments:backfill')
            ->expectsOutputToContain('rien à faire')
            ->assertSuccessful();

        $this->assertSame($codes, Medicament::orderBy('id')->pluck('code')->all());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les interactions — une relation, pas une propriété
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_interaction_est_stockee_en_couple_ordonne(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);

        // Déclarée dans l'ordre inverse : le service doit la remettre à l'endroit.
        $interaction = $this->interactions()->declarer($b, $a, 'contre_indication', 'Risque hémorragique majeur.', null, 'Thesaurus');

        $this->assertSame($a->id, $interaction->medicament_a_id);
        $this->assertSame($b->id, $interaction->medicament_b_id);
    }

    public function test_la_meme_interaction_declaree_dans_l_autre_sens_est_refusee(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);

        $this->interactions()->declarer($a, $b, 'contre_indication', 'Risque hémorragique.', null, 'Thesaurus');

        try {
            $this->interactions()->declarer($b, $a, 'precaution', 'Autre avis.', null, 'Autre source');
            $this->fail('Le référentiel a accepté deux affirmations sur le même couple de médicaments.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('medicament_b_id', $e->errors());
        }

        $this->assertSame(1, InteractionMedicamenteuse::count());
    }

    public function test_le_moteur_refuse_un_couple_ecrit_a_l_envers(): void
    {
        // CE VECTEUR NAÎT DU G2 : l'index unique ne protège que le couple DÉJÀ ordonné, donc une
        // écriture directe en (B, A) alors que (A, B) existe passait — le référentiel aurait porté
        // deux affirmations sur le même fait clinique. Le service ordonnait déjà, mais une garantie
        // qui ne tient qu'au chemin applicatif se contourne par un import ou une correction à la
        // main. C'est désormais le moteur qui refuse.
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \DB::table('interactions_medicamenteuses')->insert([
            'medicament_a_id' => $b->id,      // à l'envers, délibérément
            'medicament_b_id' => $a->id,
            'niveau'          => 'precaution',
            'description'     => 'Écrite à l\'envers.',
            'source'          => 'Import brut',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_un_medicament_ne_peut_pas_interagir_avec_lui_meme(): void
    {
        $a = $this->medicament();

        try {
            $this->interactions()->declarer($a, $a, 'precaution', 'Absurde.', null, 'Source');
            $this->fail('Un médicament a pu être déclaré en interaction avec lui-même.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('medicament_b_id', $e->errors());
        }

        $this->assertSame(0, InteractionMedicamenteuse::count());
    }

    public function test_la_lecture_ne_renvoie_que_les_couples_entierement_dans_la_liste(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);
        $c = $this->medicament(['nom_generique' => 'Amoxicilline']);

        $this->interactions()->declarer($a, $b, 'contre_indication', 'Hémorragie.', null, 'Thesaurus');
        $this->interactions()->declarer($a, $c, 'precaution', 'Surveillance.', null, 'Thesaurus');

        // Le patient ne prend que A et B : l'interaction A/C ne le concerne pas, et l'afficher
        // ferait passer une information générale pour une alerte le visant.
        $trouvees = $this->interactions()->entre([$a->id, $b->id]);

        $this->assertCount(1, $trouvees);
        $this->assertSame('contre_indication', $trouvees->first()->niveau);
    }

    public function test_les_interactions_sont_lisibles_depuis_les_deux_cotes_du_couple(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);

        $this->interactions()->declarer($a, $b, 'contre_indication', 'Hémorragie.', null, 'Thesaurus');

        // Sans la requête sur les deux colonnes, la moitié des médicaments auraient une liste vide
        // — et le défaut serait invisible tant qu'on ne testerait qu'un seul sens.
        $this->assertSame(1, $a->interactions()->count());
        $this->assertSame(1, $b->interactions()->count());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La projection gouvernée — deux vecteurs EN MIROIR
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_referentiel_est_inscrit_au_registre(): void
    {
        $this->assertTrue(RegistreReferentiels::existe(SourceMedicaments::CODE));
    }

    public function test_un_releve_de_prix_en_pharmacie_ne_fait_PAS_diverger_le_referentiel(): void
    {
        $medicament = $this->medicament(['prix_reference_cfa' => 500]);
        $this->attribuerLesCodes();

        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        // Un citoyen relève un prix dans une officine : c'est de la donnée d'observation, elle vit
        // dans `prix_pharmacie` et n'engage aucune autorité.
        $structure = StructureSanitaire::create([
            'nom' => 'Pharmacie du Plateau', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Plateau', 'latitude' => 5.32, 'longitude' => -4.02,
        ]);
        // Par le chemin RÉEL du module 5, pas par une insertion fabriquée : c'est ce chemin-là
        // qu'on affirme sans effet sur le référentiel national.
        app(PrixMedicamentService::class)->releverPrix(
            $medicament->fresh(),
            $structure,
            750,
            'crowdsource_patient',
            User::factory()->create(),
        );

        $this->assertSame(1, PrixPharmacie::count());

        $apres = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $this->assertSame($avant, $apres, 'Un relevé de prix citoyen a fait diverger le référentiel national.');
    }

    public function test_un_dosage_corrige_FAIT_diverger_le_referentiel(): void
    {
        $medicament = $this->medicament();
        $this->attribuerLesCodes();

        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $medicament->update(['forme' => 'comprime', 'dosage' => '500 mg']);

        $apres = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $this->assertNotSame($avant, $apres, 'Corriger un dosage n\'a pas fait diverger le référentiel.');
    }

    public function test_une_interaction_declaree_fait_diverger_le_referentiel(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);
        $this->attribuerLesCodes();

        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $this->interactions()->declarer($a->fresh(), $b->fresh(), 'contre_indication', 'Hémorragie.', null, 'Thesaurus');

        $apres = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $this->assertNotSame($avant, $apres, 'Une interaction déclarée est restée hors du référentiel.');
    }

    public function test_l_instantane_porte_les_interactions_par_code_national(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);
        $this->attribuerLesCodes();
        $this->interactions()->declarer($a->fresh(), $b->fresh(), 'contre_indication', 'Hémorragie.', null, 'Thesaurus');

        $contenu = $this->source()->extraire();
        $interaction = collect($contenu)->firstWhere('type', 'interaction');

        $this->assertNotNull($interaction);
        // Par CODE, jamais par identifiant technique : un instantané doit rester rejouable sans la
        // base qui l'a produit.
        $this->assertSame('MED000001', $interaction['medicament_a']);
        $this->assertSame('MED000002', $interaction['medicament_b']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Contrôles qualité
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_medicament_sans_code_empeche_la_publication(): void
    {
        $this->medicament();

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('aucun code national', implode(' ', $erreurs));
    }

    public function test_le_meme_code_dans_deux_pays_n_est_pas_un_doublon(): void
    {
        // LE DÉFAUT TROUVÉ AU G2 DE P6.5a : comparer les codes sans le pays rendait le référentiel
        // impubliable dès le second pays, le contrôle étant plus strict que l'index.
        $ci = $this->medicament(['nom_generique' => 'Paracétamol CI']);
        app(AttributeurCodeMedicament::class)->attribuer($ci);

        $sn = $this->medicament(['nom_generique' => 'Paracétamol SN']);
        $sn->forceFill(['pays_code' => 'SN'])->save();
        app(AttributeurCodeMedicament::class)->attribuer($sn->fresh());

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertSame([], array_filter($erreurs, fn (string $e) => str_contains($e, 'Doublon')));
    }

    public function test_un_generique_et_sa_marque_ne_sont_PAS_un_doublon(): void
    {
        // VECTEUR NÉ DU G2 : le jeu de développement contient « Amoxicilline 500 mg » deux fois —
        // la ligne générique avec sa référence CENAME, et « Clamoxyl ». Un contrôle sur la seule
        // DCI les aurait signalés comme doublons et aurait rendu le référentiel impubliable.
        $this->medicament(['nom_generique' => 'Amoxicilline', 'dosage' => '500 mg', 'forme' => 'gelule', 'cename_reference' => 'CEN-AMOX-500']);
        $this->medicament(['nom_generique' => 'Amoxicilline', 'dosage' => '500 mg', 'forme' => 'gelule', 'nom_commercial' => 'Clamoxyl']);
        $this->attribuerLesCodes();

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertSame([], array_filter($erreurs, fn (string $e) => str_contains($e, 'Doublon')));
    }

    public function test_le_meme_produit_saisi_deux_fois_est_signale(): void
    {
        // Le miroir du vecteur précédent : molécule, dosage, marque et fabricant identiques.
        $commun = ['nom_generique' => 'Amoxicilline', 'dosage' => '500 mg', 'forme' => 'gelule',
            'nom_commercial' => 'Clamoxyl', 'laboratoire' => 'GSK'];

        $this->medicament($commun);
        $this->medicament($commun);
        $this->attribuerLesCodes();

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertStringContainsString('apparaît deux fois avec le même dosage', implode(' ', $erreurs));
    }

    public function test_un_dosage_sans_forme_est_signale(): void
    {
        $this->medicament(['dosage' => '500 mg']);
        $this->attribuerLesCodes();

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertStringContainsString('dosage est renseigné sans forme', implode(' ', $erreurs));
    }

    public function test_une_interaction_sans_source_est_refusee_a_la_publication(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);
        $this->attribuerLesCodes();
        $this->interactions()->declarer($a->fresh(), $b->fresh(), 'contre_indication', 'Hémorragie.', null, null);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertStringContainsString('source absente', implode(' ', $erreurs));
    }

    public function test_un_referentiel_complet_ne_produit_aucune_erreur(): void
    {
        $a = $this->medicament([
            'nom_generique' => 'Paracétamol', 'forme' => 'comprime', 'dosage' => '500 mg',
            'voie_administration' => 'orale', 'prix_reference_cfa' => 500,
        ]);
        $b = $this->medicament([
            'nom_generique' => 'Warfarine', 'forme' => 'comprime', 'dosage' => '5 mg',
            'voie_administration' => 'orale',
        ]);
        $this->attribuerLesCodes();
        $this->interactions()->declarer($a->fresh(), $b->fresh(), 'precaution', 'Surveillance de l\'INR.', null, 'Thesaurus ANSM');

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La garde d'écriture du catalogue national
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_permission_d_une_officine_n_ouvre_PAS_le_catalogue_national(): void
    {
        $medicament = $this->medicament();

        // `medicament.manage` = « prix et ruptures de SA pharmacie ». L'étendre au catalogue
        // national laisserait une officine écrire indications et interactions.
        $agent = $this->gestionnaireOfficine();
        $this->assertTrue($agent->can('medicament.manage'), 'Le vecteur ne prouverait rien sans cette permission.');

        $this->actingAs($agent, 'web')
            ->get(route('portail.medicaments.index'))
            ->assertForbidden();

        $this->actingAs($agent, 'web')
            ->put(route('portail.medicaments.update', $medicament), [
                'nom_generique' => 'Détourné', 'categorie' => 'X', 'statut_marche' => 'autorise',
            ])
            ->assertForbidden();

        $this->assertSame('Paracétamol', $medicament->fresh()->nom_generique);
    }

    public function test_la_permission_du_referentiel_ouvre_l_ecran(): void
    {
        $this->medicament();
        $agent = $this->agentReferentiel();

        $this->actingAs($agent, 'web')
            ->get(route('portail.medicaments.index'))
            ->assertOk();
    }

    public function test_le_portail_ignore_le_code_national_envoye_par_le_client(): void
    {
        $medicament = $this->medicament();
        $agent = $this->agentReferentiel();

        $this->actingAs($agent, 'web')
            ->put(route('portail.medicaments.update', $medicament), [
                'nom_generique' => 'Paracétamol',
                'categorie'     => 'Analgésique',
                'statut_marche' => 'autorise',
                'forme'         => 'comprime',
                'dosage'        => '500 mg',
                'code'          => 'MED999999',
                'pays_code'     => 'XX',
            ])
            ->assertRedirect();

        $frais = $medicament->fresh();

        $this->assertSame('MED000001', $frais->code, 'Le client a pu choisir le code national.');
        $this->assertSame('CI', $frais->pays_code);
        $this->assertSame('500 mg', $frais->dosage);
    }

    public function test_les_enumerations_sont_servies_par_l_api_publique(): void
    {
        $this->medicament();

        // Sans cela, chaque client recopierait les libellés — le défaut de P6.4b, où sept libellés
        // vivaient en dur côté mobile et avaient divergé de la base.
        $reponse = $this->getJson('/api/v1/medicaments')->assertOk();

        $this->assertNotEmpty($reponse->json('enumerations.formes'));
        $this->assertNotEmpty($reponse->json('enumerations.voies'));
        $this->assertNotEmpty($reponse->json('enumerations.niveaux_interaction'));

        $formes = array_column($reponse->json('enumerations.formes'), 'valeur');
        $this->assertSame(Medicaments::formes(), $formes);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Garde anti-divergence : migration ↔ source unique
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_enumerations_de_la_base_et_la_source_unique_ne_divergent_pas(): void
    {
        // La migration écrit ses listes en toutes lettres — un enregistrement historique ne doit pas
        // changer de sens quand la classe applicative évolue. Ce test est le prix de cette
        // duplication : sans lui, ajouter une forme dans `Medicaments` sans migration produirait une
        // valeur que le formulaire propose et que la base refuse (convention posée en P6.5a).
        foreach (Medicaments::formes() as $forme) {
            $m = $this->medicament(['nom_generique' => "Forme {$forme}", 'forme' => $forme]);
            $this->assertSame($forme, $m->fresh()->forme);
        }

        foreach (Medicaments::voies() as $voie) {
            $m = $this->medicament(['nom_generique' => "Voie {$voie}", 'voie_administration' => $voie]);
            $this->assertSame($voie, $m->fresh()->voie_administration);
        }

        foreach (Medicaments::statutsMarche() as $statut) {
            $m = $this->medicament(['nom_generique' => "Statut {$statut}", 'statut_marche' => $statut]);
            $this->assertSame($statut, $m->fresh()->statut_marche);
        }

        foreach (Medicaments::statutsGenerique() as $statut) {
            $m = $this->medicament(['nom_generique' => "Générique {$statut}", 'statut_generique' => $statut]);
            $this->assertSame($statut, $m->fresh()->statut_generique);
        }

        $a = $this->medicament(['nom_generique' => 'Interaction A']);

        foreach (Medicaments::niveauxInteraction() as $index => $niveau) {
            $b = $this->medicament(['nom_generique' => "Interaction B{$index}"]);
            $i = $this->interactions()->declarer($a->fresh(), $b, $niveau, 'Vecteur de parité.', null, 'Test');
            $this->assertSame($niveau, $i->fresh()->niveau);
        }
    }

    public function test_un_medicament_retire_reste_visible_au_catalogue(): void
    {
        // Le statut est une DONNÉE, pas un filtre : masquer un produit retiré empêcherait un
        // pharmacien de comprendre pourquoi il ne doit plus le délivrer.
        $this->medicament(['nom_generique' => 'Produit retiré', 'statut_marche' => 'retire']);

        $reponse = $this->getJson('/api/v1/medicaments?q=retir')->assertOk();

        $this->assertCount(1, $reponse->json('medicaments'));
        $this->assertSame('retire', $reponse->json('medicaments.0.statut_marche'));
    }
}
