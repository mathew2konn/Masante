<?php

namespace Tests\Feature;

use App\Models\Referentiel;
use App\Models\ReferentielJournal;
use App\Models\ReferentielMesure;
use App\Models\ReferentielVersion;
use App\Models\Symptome;
use App\Models\User;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\JournalReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Services\Referentiel\SourceSeuilsMesure;
use App\Services\Referentiel\SourceSymptomesTriage;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6.3 — Socle référentiel : registre, versionnage, gouvernance §10, audit §11, diffusion.
 *
 * ÉCRITE DANS LES DEUX SENS. Chaque garde a son vecteur qui passe ET son vecteur qui refuse :
 * l'habilitation, le quatre-yeux, l'anti-substitution, les contrôles qualité et l'unicité de la
 * proposition ne se rattrapent pas l'une l'autre, et une suite qui ne vérifierait que le chemin
 * heureux ne prouverait aucune d'entre elles.
 *
 * CE QU'ELLE PROTÈGE EN PARTICULIER — l'invariant qui donne son sens au module : une version
 * publiée est SCELLÉE. Sans cela, l'estampille « cette décision s'appuie sur la version 7 » ne
 * prouverait rien, puisqu'on pourrait réécrire la version 7 après coup.
 */
class SocleReferentielTest extends TestCase
{
    use RefreshDatabase;

    private const SEUILS = SourceSeuilsMesure::CODE;

    private const SYMPTOMES = SourceSymptomesTriage::CODE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function gouvernance(): ServiceGouvernanceReferentiel
    {
        return app(ServiceGouvernanceReferentiel::class);
    }

    /** Un agent habilité à proposer, à décider, aux deux, ou à rien. */
    private function agent(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /** Un seuil de mesure cohérent — le contenu « sain » de référence. */
    private function seuil(array $remplacements = []): ReferentielMesure
    {
        return ReferentielMesure::create(array_merge([
            'type_mesure'     => 'glycemie',
            'libelle'         => 'Glycémie',
            'unite'           => 'g/L',
            'valeur_min'      => 0.2,
            'valeur_max'      => 6.0,
            'normal_min'      => 0.7,
            'normal_max'      => 1.1,
            'critique_bas'    => 0.5,
            'critique_haut'   => 2.5,
            'decimales'       => 2,
            'ordre'           => 1,
            'conseil_anormal' => 'Consultez un médecin sous 48 h.',
        ], $remplacements));
    }

    private function symptome(array $remplacements = []): Symptome
    {
        return Symptome::create(array_merge([
            'nom_fr'         => 'Fièvre élevée',
            'categorie'      => 'fievre',
            'poids_severite' => 60,
            'drapeau_rouge'  => false,
            'actif'          => true,
        ], $remplacements));
    }

    /** Registre + une version publiée : le point de départ de la plupart des vecteurs. */
    private function referentielPublie(string $code = self::SEUILS): Referentiel
    {
        $this->gouvernance()->enregistrer($code);

        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        $this->gouvernance()->proposer($code, 'CI', $auteur, 'Première mise en vigueur nationale.');
        $this->gouvernance()->publier($code, 'CI', $decideur, 'Contrôles conformes, publication.');

        return Referentiel::where('code', $code)->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Registre
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_enregistrer_inscrit_le_referentiel_et_journalise(): void
    {
        $referentiel = $this->gouvernance()->enregistrer(self::SEUILS);

        $this->assertSame(self::SEUILS, $referentiel->code);
        $this->assertSame('CI', $referentiel->pays_code);
        $this->assertNull($referentiel->version_publiee_numero, 'Un enregistrement ne publie rien.');
        $this->assertDatabaseCount('referentiel_journal', 1);
    }

    public function test_enregistrer_est_idempotent_et_ne_rejournalise_pas(): void
    {
        $this->gouvernance()->enregistrer(self::SEUILS);
        $this->gouvernance()->enregistrer(self::SEUILS);
        $this->gouvernance()->enregistrer(self::SEUILS);

        $this->assertDatabaseCount('referentiels', 1);
        // Le point qui compte : un seeder rejoué ne pollue pas la chaîne d'audit.
        $this->assertDatabaseCount('referentiel_journal', 1);
    }

    public function test_un_referentiel_hors_liste_blanche_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gouvernance()->enregistrer('table_arbitraire');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Habilitation (§10 « accès en écriture strictement réservé »)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_proposer_sans_habilitation_est_refuse(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        try {
            $this->gouvernance()->proposer(self::SEUILS, 'CI', $this->agent(), 'Tentative sans droit.');
            $this->fail('Un compte sans habilitation a pu proposer un changement de référentiel.');
        } catch (ReferentielException $e) {
            $this->assertSame(403, $e->statut);
        }

        $this->assertDatabaseCount('referentiel_versions', 0);
    }

    public function test_publier_exige_une_habilitation_distincte_de_proposer(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Mise en vigueur initiale.');

        // Un second agent qui ne porte QUE `proposer` ne peut pas décider.
        $autre = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);

        try {
            $this->gouvernance()->publier(self::SEUILS, 'CI', $autre, 'Je publie sans en avoir le droit.');
            $this->fail('Un compte sans `referentiel.publier` a pu publier.');
        } catch (ReferentielException $e) {
            $this->assertSame(403, $e->statut);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Cycle de vie §10
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_proposer_fige_le_contenu_actuel(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        $version = $this->gouvernance()->proposer(
            self::SEUILS,
            'CI',
            $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER),
            'Alignement sur les seuils OMS 2026.',
        );

        $this->assertSame(1, $version->numero);
        $this->assertSame(ReferentielVersion::PROPOSITION, $version->statut);
        $this->assertSame(1, $version->nb_entrees);
        $this->assertSame('glycemie', $version->contenu_json[0]['type_mesure']);
        $this->assertNull($version->decide_le);
    }

    public function test_une_seule_proposition_a_la_fois(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);
        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);

        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Première proposition.');

        try {
            $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Seconde proposition.');
            $this->fail('Deux propositions ont pu coexister sur le même référentiel.');
        } catch (ReferentielException $e) {
            $this->assertSame(409, $e->statut);
        }

        $this->assertDatabaseCount('referentiel_versions', 1);
    }

    public function test_proposer_un_contenu_identique_a_la_version_publiee_est_refuse(): void
    {
        $this->seuil();
        $this->referentielPublie();

        try {
            $this->gouvernance()->proposer(
                self::SEUILS,
                'CI',
                $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER),
                'Proposition sans le moindre changement.',
            );
            $this->fail('Une version identique à celle en vigueur a pu être proposée.');
        } catch (ReferentielException $e) {
            $this->assertSame(409, $e->statut);
        }
    }

    public function test_publication_met_le_registre_a_jour_et_archive_la_precedente(): void
    {
        $this->seuil();
        $referentiel = $this->referentielPublie();

        $this->assertSame(1, $referentiel->version_publiee_numero);
        $this->assertNotNull($referentiel->publiee_le);

        // Un changement réel dans la table métier, puis une seconde publication.
        ReferentielMesure::where('type_mesure', 'glycemie')->update(['normal_max' => 1.2]);

        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);
        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Relèvement du maximum normal.');
        $this->gouvernance()->publier(self::SEUILS, 'CI', $decideur, 'Validé par le comité clinique.');

        $referentiel->refresh();
        $this->assertSame(2, $referentiel->version_publiee_numero);

        $this->assertSame(
            ReferentielVersion::ARCHIVEE,
            ReferentielVersion::where('referentiel_id', $referentiel->id)->where('numero', 1)->value('statut'),
            'La version remplacée doit être archivée, jamais supprimée : elle explique les décisions '
            .'prises pendant qu\'elle était en vigueur.',
        );
    }

    public function test_l_auteur_ne_peut_pas_valider_sa_propre_proposition(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        // Un seul agent qui porte les deux habilitations : c'est le quatre-yeux, pas le droit,
        // qui doit l'arrêter.
        $seul = $this->agent(
            ServiceGouvernanceReferentiel::PERMISSION_PROPOSER,
            ServiceGouvernanceReferentiel::PERMISSION_PUBLIER,
        );

        $this->gouvernance()->proposer(self::SEUILS, 'CI', $seul, 'Je propose et je publierai.');

        try {
            $this->gouvernance()->publier(self::SEUILS, 'CI', $seul, 'Je me valide moi-même.');
            $this->fail('Un auteur a pu publier sa propre proposition (CDC_09 §10 double validation).');
        } catch (ReferentielException $e) {
            $this->assertSame(409, $e->statut);
        }
    }

    public function test_l_auteur_ne_peut_pas_non_plus_rejeter_sa_proposition(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        $seul = $this->agent(
            ServiceGouvernanceReferentiel::PERMISSION_PROPOSER,
            ServiceGouvernanceReferentiel::PERMISSION_PUBLIER,
        );
        $this->gouvernance()->proposer(self::SEUILS, 'CI', $seul, 'Proposition à retirer.');

        // Refuser un changement est une décision de gouvernance au même titre que l'accepter.
        $this->expectException(ReferentielException::class);
        $this->gouvernance()->rejeter(self::SEUILS, 'CI', $seul, 'Je retire ma propre proposition.');
    }

    public function test_le_quatre_yeux_est_aussi_garanti_en_base(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);
        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $version = $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Proposition à décider.');

        // Écriture SQL directe, hors du service : c'est le CHECK qui doit refuser, sinon la règle
        // ne tiendrait que par la discipline du code appelant.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('referentiel_versions')->where('id', $version->id)->update([
            'statut'         => ReferentielVersion::PUBLIEE,
            'verrou_unicite' => 'V:'.$version->referentiel_id,
            'decide_par'     => $auteur->id,
            'decide_le'      => now(),
        ]);
    }

    public function test_rejeter_libere_la_place_pour_une_nouvelle_proposition(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);
        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Proposition contestée.');
        $rejetee = $this->gouvernance()->rejeter(self::SEUILS, 'CI', $decideur, 'Seuils non justifiés.');

        $this->assertSame(ReferentielVersion::REJETEE, $rejetee->statut);
        $this->assertNull($rejetee->verrou_unicite);

        $suivante = $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Proposition corrigée.');
        $this->assertSame(2, $suivante->numero, 'Le numéro de version ne se réutilise pas.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Anti-substitution
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_contenu_modifie_apres_la_proposition_ne_peut_pas_etre_publie(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);
        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Contenu soumis à relecture.');

        // Quelqu'un modifie la table métier APRÈS la relecture. Publier maintenant mettrait en
        // vigueur un contenu que personne n'a relu — et surtout ferait diverger le référentiel
        // diffusé de la table que lit réellement le module des mesures.
        ReferentielMesure::where('type_mesure', 'glycemie')->update(['critique_haut' => 9.9]);

        try {
            $this->gouvernance()->publier(self::SEUILS, 'CI', $decideur, 'Publication du contenu relu.');
            $this->fail('Un contenu modifié après la relecture a pu être publié.');
        } catch (ReferentielException $e) {
            $this->assertSame(409, $e->statut);
        }

        $this->assertNull(Referentiel::where('code', self::SEUILS)->value('version_publiee_numero'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Contrôles qualité §10 — bloquants à la publication
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_des_seuils_incoherents_ne_peuvent_pas_etre_publies(): void
    {
        // La plage normale sort de la plage plausible : une glycémie normale serait rejetée à la
        // saisie comme une faute de frappe.
        $this->seuil(['normal_max' => 12.0]);
        $this->gouvernance()->enregistrer(self::SEUILS);

        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        // La proposition passe : un auteur doit pouvoir soumettre un contenu à discuter.
        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Seuils à revoir avec le comité.');

        try {
            $this->gouvernance()->publier(self::SEUILS, 'CI', $decideur, 'Publication tentée.');
            $this->fail('Un contenu incohérent a pu devenir la version en vigueur.');
        } catch (ReferentielException $e) {
            $this->assertSame(422, $e->statut);
            $this->assertNotEmpty($e->details);
        }
    }

    public function test_les_controles_qualite_des_seuils_couvrent_les_cas_du_cdc(): void
    {
        $source = app(SourceSeuilsMesure::class);

        $this->assertNotEmpty($source->controlerQualite([]), 'Un référentiel vide est refusé.');

        $ligne = [
            'type_mesure' => 'pouls', 'libelle' => 'Pouls', 'unite' => '', 'valeur_min' => 20.0,
            'valeur_max' => 250.0, 'normal_min' => 60.0, 'normal_max' => 100.0,
            'critique_bas' => 80.0, 'critique_haut' => 90.0, 'decimales' => 9, 'ordre' => 1,
            'conseil_anormal' => '',
        ];

        $erreurs = $source->controlerQualite([$ligne, $ligne]);

        // Doublon, unité absente, conseil absent, décimales aberrantes, critique bas au-dessus du
        // normal bas, critique haut en dessous du normal haut.
        $this->assertGreaterThanOrEqual(6, count($erreurs));
    }

    public function test_les_controles_qualite_du_triage_refusent_les_regles_contradictoires(): void
    {
        $source = app(SourceSymptomesTriage::class);

        // Un drapeau rouge force le niveau URGENT ; à poids nul, la règle se contredit elle-même.
        $contradictoire = [[
            'id' => 1, 'nom_fr' => 'Douleur thoracique', 'categorie' => 'cardiaque',
            'poids_severite' => 0, 'specialite_hint' => null, 'drapeau_rouge' => true,
            'questions_complementaires_json' => null, 'maladies_probables_json' => null, 'actif' => true,
        ]];
        $this->assertNotEmpty($source->controlerQualite($contradictoire));

        // Poids aberrant.
        $aberrant = $contradictoire;
        $aberrant[0]['poids_severite'] = 500;
        $aberrant[0]['drapeau_rouge'] = false;
        $this->assertNotEmpty($source->controlerQualite($aberrant));

        // Doublon insensible à la casse : « Fièvre » et « fièvre » sont le même choix pour un citoyen.
        $doublon = [
            ['id' => 1, 'nom_fr' => 'Fièvre', 'categorie' => 'fievre', 'poids_severite' => 50,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => null, 'maladies_probables_json' => null, 'actif' => true],
            ['id' => 2, 'nom_fr' => 'fièvre', 'categorie' => 'fievre', 'poids_severite' => 50,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => null, 'maladies_probables_json' => null, 'actif' => true],
        ];
        $this->assertNotEmpty($source->controlerQualite($doublon));

        // Tous les symptômes désactivés : syntaxiquement valide, cliniquement inutilisable.
        $eteint = $doublon;
        $eteint[0]['actif'] = false;
        $eteint[1]['actif'] = false;
        $eteint[1]['nom_fr'] = 'Toux';
        $this->assertNotEmpty($source->controlerQualite($eteint));
    }

    public function test_un_contenu_sain_ne_produit_aucune_anomalie(): void
    {
        // Le pendant indispensable des vecteurs ci-dessus : des contrôles qui refuseraient TOUT
        // seraient tout aussi inutilisables que des contrôles qui n'attrapent rien.
        $this->seuil();
        $this->symptome();

        $this->assertSame([], app(SourceSeuilsMesure::class)->controlerQualite(
            app(SourceSeuilsMesure::class)->extraire()
        ));
        $this->assertSame([], app(SourceSymptomesTriage::class)->controlerQualite(
            app(SourceSymptomesTriage::class)->extraire()
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Immuabilité de l'instantané
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_version_publiee_est_scellee(): void
    {
        $this->seuil();
        $referentiel = $this->referentielPublie();
        $version = $referentiel->versionPubliee();

        $this->expectException(\RuntimeException::class);

        $version->contenu_json = [['type_mesure' => 'glycemie', 'normal_max' => 99.0]];
        $version->save();
    }

    public function test_une_version_ne_se_supprime_pas(): void
    {
        $this->seuil();
        $referentiel = $this->referentielPublie();

        $this->expectException(\RuntimeException::class);

        $referentiel->versionPubliee()->delete();
    }

    public function test_le_journal_est_append_only(): void
    {
        $this->seuil();
        $this->referentielPublie();
        $entree = ReferentielJournal::query()->firstOrFail();

        $this->expectException(\RuntimeException::class);

        $entree->action = 'ACTION_REECRITE';
        $entree->save();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Chaîne d'audit §11
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_chaine_d_audit_est_intacte_apres_un_cycle_complet(): void
    {
        $this->seuil();
        $this->referentielPublie();

        $etat = app(JournalReferentiel::class)->verifierChaine();

        $this->assertTrue($etat['intacte']);
        // Enregistrement + proposition + publication.
        $this->assertSame(3, $etat['entrees']);
    }

    public function test_une_entree_modifiee_en_base_rompt_la_chaine(): void
    {
        $this->seuil();
        $this->referentielPublie();

        // Écriture SQL directe : le modèle refuserait, la base non. C'est exactement la situation
        // contre laquelle le chaînage protège.
        $cible = ReferentielJournal::query()->orderBy('id')->skip(1)->firstOrFail();
        DB::table('referentiel_journal')->where('id', $cible->id)->update(['acteur_nom' => 'Quelqu\'un d\'autre']);

        $etat = app(JournalReferentiel::class)->verifierChaine();

        $this->assertFalse($etat['intacte']);
        $this->assertSame('CONTENU', $etat['rupture']['type']);
        $this->assertSame($cible->id, $etat['rupture']['id']);
    }

    public function test_une_entree_supprimee_rompt_le_chainage(): void
    {
        $this->seuil();
        $this->referentielPublie();

        $cible = ReferentielJournal::query()->orderBy('id')->skip(1)->firstOrFail();
        DB::table('referentiel_journal')->where('id', $cible->id)->delete();

        $etat = app(JournalReferentiel::class)->verifierChaine();

        $this->assertFalse($etat['intacte']);
        $this->assertSame('CHAINAGE', $etat['rupture']['type']);
    }

    public function test_le_journal_ne_contient_jamais_le_contenu_du_referentiel(): void
    {
        $this->seuil(['conseil_anormal' => 'PHRASE_TEMOIN_DU_CONSEIL_MEDICAL']);
        $this->referentielPublie();

        // Le journal prouve QU'UN changement a eu lieu et par qui ; ce QUI a changé vit dans
        // l'instantané. Deux copies feraient deux vérités.
        foreach (ReferentielJournal::all() as $entree) {
            $this->assertStringNotContainsString(
                'PHRASE_TEMOIN_DU_CONSEIL_MEDICAL',
                json_encode($entree->toArray(), JSON_UNESCAPED_UNICODE),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Empreinte
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_empreinte_ne_depend_pas_de_l_ordre_des_cles(): void
    {
        $a = [['type_mesure' => 'glycemie', 'unite' => 'g/L', 'valeur_min' => 0.2]];
        $b = [['valeur_min' => 0.2, 'unite' => 'g/L', 'type_mesure' => 'glycemie']];

        $this->assertSame(EmpreinteReferentiel::duContenu($a), EmpreinteReferentiel::duContenu($b));
    }

    public function test_l_empreinte_depend_de_l_ordre_des_listes(): void
    {
        // Une liste est ordonnée par nature — l'ordre des questions d'un symptôme est une donnée,
        // pas une commodité. Le trier changerait le sens du contenu.
        $this->assertNotSame(
            EmpreinteReferentiel::duContenu([['questions' => ['a', 'b']]]),
            EmpreinteReferentiel::duContenu([['questions' => ['b', 'a']]]),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Diffusion §10
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_diffusion_sert_la_version_publiee_et_non_la_table_en_direct(): void
    {
        $this->seuil();
        $this->referentielPublie();

        // La table métier bouge sans passer par la gouvernance.
        ReferentielMesure::where('type_mesure', 'glycemie')->update(['normal_max' => 99.0]);

        $diffuse = app(DiffusionReferentiel::class)->lire(self::SEUILS);

        $this->assertSame(1, $diffuse['version']);
        $this->assertSame(1.1, $diffuse['contenu'][0]['normal_max'],
            'La diffusion doit servir l\'instantané publié : c\'est ce décalage assumé qui permet '
            .'à une décision de citer une version.');
    }

    public function test_publier_une_nouvelle_version_change_ce_qui_est_diffuse(): void
    {
        $this->seuil();
        $this->referentielPublie();

        // Première lecture : elle met la version 1 en cache.
        $this->assertSame(1.1, app(DiffusionReferentiel::class)->lire(self::SEUILS)['contenu'][0]['normal_max']);

        ReferentielMesure::where('type_mesure', 'glycemie')->update(['normal_max' => 1.2]);
        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);
        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Relèvement du maximum normal.');
        $this->gouvernance()->publier(self::SEUILS, 'CI', $decideur, 'Validé en comité.');

        // Aucune invalidation n'a été écrite : la clé porte le numéro de version, donc la lecture
        // suivante interroge une autre clé. C'est tout le mécanisme.
        $apres = app(DiffusionReferentiel::class)->lire(self::SEUILS);

        $this->assertSame(2, $apres['version']);
        $this->assertSame(1.2, $apres['contenu'][0]['normal_max']);
    }

    public function test_une_version_archivee_reste_lisible(): void
    {
        $this->seuil();
        $this->referentielPublie();

        ReferentielMesure::where('type_mesure', 'glycemie')->update(['normal_max' => 1.2]);
        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);
        $this->gouvernance()->proposer(self::SEUILS, 'CI', $auteur, 'Relèvement du maximum normal.');
        $this->gouvernance()->publier(self::SEUILS, 'CI', $decideur, 'Validé en comité.');

        // La rejouabilité d'une décision passée tient entièrement à ceci.
        $v1 = app(DiffusionReferentiel::class)->lireVersion(self::SEUILS, 1);

        $this->assertSame(ReferentielVersion::ARCHIVEE, $v1['statut']);
        $this->assertSame(1.1, $v1['contenu'][0]['normal_max']);
    }

    public function test_une_proposition_n_est_jamais_diffusee(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);
        $this->gouvernance()->proposer(
            self::SEUILS, 'CI',
            $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER),
            'Proposition en attente de décision.',
        );

        // Elle n'a jamais été en vigueur : aucune décision n'a pu s'appuyer dessus.
        try {
            app(DiffusionReferentiel::class)->lireVersion(self::SEUILS, 1);
            $this->fail('Une proposition non décidée a été diffusée.');
        } catch (ReferentielException $e) {
            $this->assertSame(404, $e->statut);
        }
    }

    public function test_un_referentiel_sans_version_publiee_ne_diffuse_rien(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        try {
            app(DiffusionReferentiel::class)->lire(self::SEUILS);
            $this->fail('Un référentiel jamais publié a diffusé du contenu.');
        } catch (ReferentielException $e) {
            $this->assertSame(404, $e->statut);
        }
    }

    public function test_l_estampille_est_nulle_tant_que_rien_n_est_publie(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        $this->assertNull(app(DiffusionReferentiel::class)->estampille(self::SEUILS));

        $this->gouvernance()->proposer(
            self::SEUILS, 'CI',
            $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER),
            'Mise en vigueur initiale.',
        );
        $this->gouvernance()->publier(
            self::SEUILS, 'CI',
            $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER),
            'Contrôles conformes.',
        );

        $this->assertSame(
            ['referentiel' => self::SEUILS, 'pays_code' => 'CI', 'version' => 1],
            app(DiffusionReferentiel::class)->estampille(self::SEUILS),
        );
        $this->assertNotNull($auteur);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Surface HTTP
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_diffusion_est_lisible_sans_authentification(): void
    {
        $this->seuil();
        $this->referentielPublie();

        // §10 : « les référentiels sont exposés en lecture à tous les services ». Un socle
        // d'interopérabilité qu'il faut s'authentifier pour lire n'en est pas un.
        $this->getJson('/api/v1/referentiels')->assertOk()->assertJsonPath('referentiels.0.version', 1);

        $this->getJson('/api/v1/referentiels/'.self::SEUILS)
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('contenu.0.type_mesure', 'glycemie');
    }

    public function test_un_code_de_referentiel_inconnu_repond_404(): void
    {
        // Le code arrive par l'URL : sans liste blanche fermée, il serait une porte vers
        // n'importe quelle table. Il doit échouer proprement, jamais en 500.
        $this->getJson('/api/v1/referentiels/table_arbitraire')->assertNotFound();
    }

    public function test_la_gouvernance_exige_une_authentification_puis_une_habilitation(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        $this->postJson('/api/v1/referentiels/'.self::SEUILS.'/propositions', [
            'motif' => 'Tentative anonyme de changement.',
        ])->assertUnauthorized();

        $this->actingAs($this->agent())
            ->postJson('/api/v1/referentiels/'.self::SEUILS.'/propositions', [
                'motif' => 'Tentative sans habilitation.',
            ])->assertForbidden();
    }

    public function test_le_cycle_complet_par_http(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);

        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        $this->actingAs($auteur)
            ->postJson('/api/v1/referentiels/'.self::SEUILS.'/propositions', [
                'motif' => 'Alignement sur les recommandations nationales 2026.',
            ])->assertCreated()->assertJsonPath('version.numero', 1);

        $this->actingAs($decideur)
            ->postJson('/api/v1/referentiels/'.self::SEUILS.'/publication', [
                'motif' => 'Contrôles qualité conformes, mise en vigueur.',
            ])->assertOk()->assertJsonPath('version.statut', ReferentielVersion::PUBLIEE);

        $this->actingAs($decideur)
            ->getJson('/api/v1/referentiels-journal')
            ->assertOk()
            ->assertJsonPath('chaine.intacte', true);
    }

    public function test_une_decision_sans_motif_est_refusee(): void
    {
        $this->seuil();
        $this->gouvernance()->enregistrer(self::SEUILS);
        $auteur = $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);

        // Une version sans motif est une version qu'on ne saura pas expliquer dans six mois.
        $this->actingAs($auteur)
            ->postJson('/api/v1/referentiels/'.self::SEUILS.'/propositions', ['motif' => 'court'])
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Non-régression : les modules validés G5 ne bougent pas
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_triage_continue_de_lire_sa_table_en_direct(): void
    {
        $this->symptome();

        // P6.3 n'a rien changé au chemin de lecture existant : `/symptomes` reste servi par la
        // table métier, pas par la diffusion. La bascule est un incrément ultérieur, additif.
        $this->getJson('/api/v1/symptomes')->assertOk();

        // Lire les symptômes ne doit rien créer dans le socle : le triage l'ignore complètement.
        $this->assertDatabaseCount('referentiels', 0);
    }
}
