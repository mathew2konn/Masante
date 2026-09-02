<?php

namespace Tests\Feature;

use App\Models\AuditChaine;
use App\Models\Medecin;
use App\Models\Protocole;
use App\Models\ProtocoleJournal;
use App\Models\Referentiel;
use App\Models\ReferentielJournal;
use App\Models\ServiceEtablissement;
use App\Models\SignatureJournal;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Audit\ChaineAudit;
use App\Services\Pki\JournalSignature;
use App\Services\Protocole\JournalProtocole;
use App\Services\Referentiel\JournalReferentiel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Les chaînes d'audit : origine déclarée, ancrage de tête, identifiants non référentiels.
 *
 * Chaque vecteur tient une garantie et une seule ; ils sont écrits dans les deux sens (ce qui doit
 * casser casse, ce qui ne doit pas casser ne casse pas).
 */
class ChaineAuditTest extends TestCase
{
    use RefreshDatabase;

    private function journal(): JournalProtocole
    {
        return app(JournalProtocole::class);
    }

    private function protocole(): Protocole
    {
        // `code`, `nom` et `rang` sont hors `$fillable` par conception (un client ne choisit pas
        // l'identité d'un protocole) : le vecteur passe donc par `forceCreate`.
        return Protocole::forceCreate([
            'code' => 'TEST-CHAINE',
            'pays_code' => 'CI',
            'titre' => 'Protocole de vecteur',
            'domaine' => 'triage',
            'niveau_source' => 'national',
            'actif' => true,
        ]);
    }

    private function acteur(string $prenom = 'Ama'): User
    {
        return User::factory()->create(['prenom' => $prenom, 'nom' => 'Testeuse']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════
    //  ORIGINE
    // ═══════════════════════════════════════════════════════════════════════════════════════

    public function test_une_chaine_sans_declaration_d_origine_n_est_pas_declaree_intacte(): void
    {
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());

        // On efface la déclaration posée à l'installation : la chaîne devient exactement celle
        // qu'on ne savait pas diagnostiquer avant cet incrément.
        AuditChaine::query()->where('journal', 'protocole_journal')->delete();

        $etat = $this->journal()->verifierChaine();

        $this->assertFalse($etat['intacte']);
        $this->assertFalse($etat['origine_declaree']);
        $this->assertSame('ORIGINE', $etat['rupture']['type']);
        $this->assertStringContainsString('ne déclare pas son origine', $etat['rupture']['message']);
    }

    public function test_une_chaine_declaree_et_alimentee_est_intacte(): void
    {
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());
        $this->journal()->inscrire($protocole, 'publication', $this->acteur('Bakary'));

        $etat = $this->journal()->verifierChaine();

        $this->assertTrue($etat['intacte']);
        $this->assertTrue($etat['origine_declaree']);
        $this->assertTrue($etat['origine_conforme']);
        $this->assertSame(2, $etat['entrees']);
        $this->assertNull($etat['rupture']);
    }

    public function test_vider_puis_realimenter_une_chaine_declaree_est_detecte(): void
    {
        // LE VECTEUR CENTRAL. C'est l'accident réel : les entrées sont supprimées, puis d'autres
        // sont écrites — elles repartent d'une empreinte précédente nulle et, sans ancrage de tête,
        // la chaîne se revérifierait « intacte » en ayant perdu toute son histoire.
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());
        $this->journal()->inscrire($protocole, 'publication', $this->acteur());

        $this->assertTrue($this->journal()->verifierChaine()['intacte']);

        ProtocoleJournal::query()->delete();
        $this->journal()->inscrire($protocole, 'archivage', $this->acteur());

        $etat = $this->journal()->verifierChaine();

        $this->assertFalse($etat['intacte']);
        $this->assertTrue($etat['origine_declaree']);
        $this->assertFalse($etat['origine_conforme']);
        $this->assertSame('ORIGINE', $etat['rupture']['type']);
        $this->assertStringContainsString('ancrée', $etat['rupture']['message']);
    }

    public function test_supprimer_la_tete_d_une_chaine_ne_passe_pas_pour_intacte(): void
    {
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());
        $this->journal()->inscrire($protocole, 'publication', $this->acteur());

        // Suppression EN SQL : le modèle est append-only (P10b-2), mais ce garde-fou applicatif
        // n'atteint pas quelqu'un qui écrit directement en base — et c'est ce cas-là qu'une chaîne
        // d'audit existe pour rendre visible.
        DB::table('protocole_journal')->orderBy('id')->limit(1)->delete();

        $etat = $this->journal()->verifierChaine();

        $this->assertFalse($etat['intacte']);
        $this->assertNotNull($etat['rupture']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════
    //  LES DEUX RUPTURES HISTORIQUES SONT CONSERVÉES
    // ═══════════════════════════════════════════════════════════════════════════════════════

    public function test_supprimer_une_entree_du_milieu_rompt_le_chainage(): void
    {
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());
        $this->journal()->inscrire($protocole, 'validation', $this->acteur());
        $this->journal()->inscrire($protocole, 'publication', $this->acteur());

        $milieu = ProtocoleJournal::query()->orderBy('id')->skip(1)->first();
        DB::table('protocole_journal')->where('id', $milieu->id)->delete();

        $etat = $this->journal()->verifierChaine();

        $this->assertFalse($etat['intacte']);
        $this->assertSame('CHAINAGE', $etat['rupture']['type']);
    }

    public function test_modifier_une_entree_rompt_le_contenu(): void
    {
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());
        $entree = $this->journal()->inscrire($protocole, 'publication', $this->acteur());

        DB::table('protocole_journal')->where('id', $entree->id)->update(['acteur_nom' => 'Système']);

        $etat = $this->journal()->verifierChaine();

        $this->assertFalse($etat['intacte']);
        $this->assertSame('CONTENU', $etat['rupture']['type']);
        $this->assertSame($entree->id, $etat['rupture']['id']);
    }

    public function test_le_volume_annonce_est_celui_de_la_chaine_pas_celui_du_parcours(): void
    {
        // Défaut trouvé au G2 LIVE, pas ici : la boucle s'arrête à la première rupture, donc
        // compter les tours annonçait « 1 entrée » pour une chaîne qui en portait 34 — et le
        // scellement inscrivait ce chiffre dans le marbre. Invisible en test tant que les chaînes
        // y sont courtes : il a fallu 34 entrées réelles pour le voir.
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());
        $deuxieme = $this->journal()->inscrire($protocole, 'validation', $this->acteur());
        $this->journal()->inscrire($protocole, 'publication', $this->acteur());

        DB::table('protocole_journal')->where('id', $deuxieme->id)->update(['acteur_nom' => 'Falsifié']);

        $etat = $this->journal()->verifierChaine();

        $this->assertSame('CONTENU', $etat['rupture']['type']);
        $this->assertSame(3, $etat['entrees'], 'la chaîne porte 3 entrées, même si le parcours a cassé à la 2e');
    }

    public function test_une_rupture_de_contenu_l_emporte_sur_l_absence_d_origine(): void
    {
        // L'ordre est délibéré : CONTENU désigne une entrée par son identifiant, ORIGINE non.
        // C'est le fait le plus précis qu'un humain doit lire en premier dans un litige.
        $protocole = $this->protocole();
        $entree = $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());

        AuditChaine::query()->where('journal', 'protocole_journal')->delete();
        DB::table('protocole_journal')->where('id', $entree->id)->update(['acteur_nom' => 'Autre']);

        $etat = $this->journal()->verifierChaine();

        $this->assertSame('CONTENU', $etat['rupture']['type']);
        $this->assertFalse($etat['origine_declaree'], "l'absence d'origine reste rendue à part");
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════
    //  LE DÉFAUT QUI A CASSÉ LA CHAÎNE DE PRODUCTION
    // ═══════════════════════════════════════════════════════════════════════════════════════

    public function test_supprimer_le_compte_d_un_acteur_ne_rompt_plus_la_chaine(): void
    {
        $protocole = $this->protocole();
        $acteur = $this->acteur();

        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $acteur);
        $this->journal()->inscrire($protocole, 'publication', $this->acteur('Bakary'));

        $acteur->delete();

        $etat = $this->journal()->verifierChaine();

        $this->assertTrue($etat['intacte'], 'supprimer un compte est un droit, pas une falsification');
        $this->assertSame(
            'Ama Testeuse',
            ProtocoleJournal::query()->orderBy('id')->first()->acteur_nom,
            'le nom lisible survit au compte'
        );
    }

    public function test_supprimer_le_compte_d_un_acteur_ne_rompt_pas_la_chaine_des_referentiels(): void
    {
        $referentiel = Referentiel::forceCreate([
            'code' => 'test_chaine', 'pays_code' => 'CI', 'libelle' => 'Vecteur',
            'role_responsable' => 'admin_ivoirsante',
        ]);

        $acteur = $this->acteur('Awa');
        $journal = app(JournalReferentiel::class);

        $journal->inscrire($referentiel, 'REFERENTIEL_ENREGISTRE', $acteur);
        $journal->inscrire($referentiel, 'PROPOSITION_DEPOSEE', $this->acteur('Bakary'));

        $acteur->delete();

        $this->assertTrue($journal->verifierChaine()['intacte']);
        $this->assertSame('Awa Testeuse', ReferentielJournal::query()->orderBy('id')->first()->acteur_nom);
    }

    public function test_supprimer_un_medecin_ne_rompt_pas_la_chaine_des_signatures(): void
    {
        // Le cas le plus lourd de conséquence : la chaîne qui prouve qu'une ordonnance signée n'a
        // pas été altérée cassait quand un praticien quittait le système.
        $structure = StructureSanitaire::create([
            'nom' => 'CHU de vecteur', 'type' => 'chu', 'adresse' => 'Boulevard',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);
        $medecin = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id,
            'nom' => 'Kablan', 'prenom' => 'Koffi', 'specialite' => 'Cardiologie', 'actif' => true,
        ]);
        $journal = app(JournalSignature::class);

        $journal->inscrire('SIGNATURE_APPOSEE', $this->acteur(), $medecin, 'ordonnance', 1);
        $journal->inscrire('SIGNATURE_REFUSEE', $this->acteur(), $medecin, 'ordonnance', 2, 'controle:revocation');

        $medecin->delete();

        $this->assertTrue($journal->verifierChaine()['intacte']);
        $this->assertNull($journal->premierMaillonRompu());
        $this->assertSame(2, SignatureJournal::query()->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════
    //  SCELLEMENT
    // ═══════════════════════════════════════════════════════════════════════════════════════

    public function test_sceller_n_efface_rien_et_inscrit_le_verdict_de_la_chaine_close(): void
    {
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());
        $entree = $this->journal()->inscrire($protocole, 'publication', $this->acteur());

        // On casse volontairement la chaîne : elle doit être scellée EN TANT QUE rompue.
        DB::table('protocole_journal')->where('id', $entree->id)->update(['acteur_nom' => 'Falsifié']);

        $empreintesAvant = ProtocoleJournal::query()->orderBy('id')->pluck('empreinte')->all();

        ChaineAudit::ouvrir($this->journal(), 'Comptes de test supprimés au G2.', 'Exploitant');

        $empreintesApres = ProtocoleJournal::query()->orderBy('id')->pluck('empreinte')->all();
        $this->assertSame($empreintesAvant, $empreintesApres, 'aucune empreinte ne doit être recalculée');
        $this->assertSame(2, ProtocoleJournal::query()->count(), 'aucune entrée ne doit disparaître');

        $etat = $this->journal()->verifierChaine();

        $this->assertSame(2, $etat['chaine_courante']);
        $this->assertSame(0, $etat['entrees']);
        $this->assertTrue($etat['intacte'], 'la chaîne neuve est déclarée, donc vérifiable');

        $scellee = $etat['chaines_scellees'][0];
        $this->assertSame(1, $scellee['numero']);
        $this->assertSame('Exploitant', $scellee['scellee_par']);
        $this->assertFalse($scellee['verdict_au_scellement']['intacte']);
        $this->assertSame('CONTENU', $scellee['verdict_au_scellement']['rupture']['type']);
        $this->assertFalse($scellee['verdict_actuel']['intacte'], 'la chaîne close reste vérifiée');
    }

    public function test_la_chaine_neuve_repart_d_une_empreinte_precedente_nulle(): void
    {
        $protocole = $this->protocole();
        $this->journal()->inscrire($protocole, 'brouillon_ouvert', $this->acteur());

        ChaineAudit::ouvrir($this->journal(), 'Recommencement.', 'Exploitant');

        $neuve = $this->journal()->inscrire($protocole, 'publication', $this->acteur());

        $this->assertSame(2, $neuve->chaine);
        $this->assertNull($neuve->empreinte_precedente, 'une chaîne neuve ne s\'accroche pas à l\'ancienne');
        $this->assertTrue($this->journal()->verifierChaine()['intacte']);
    }

    public function test_sceller_sans_motif_est_refuse(): void
    {
        $this->journal()->inscrire($this->protocole(), 'brouillon_ouvert', $this->acteur());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exige un motif écrit');

        ChaineAudit::ouvrir($this->journal(), '   ', 'Exploitant');
    }

    public function test_sceller_une_chaine_vide_est_refuse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("il n'y a rien à sceller");

        ChaineAudit::ouvrir($this->journal(), 'Motif quelconque.', 'Exploitant');
    }

    public function test_la_commande_refuse_un_journal_hors_liste_blanche(): void
    {
        $this->artisan('masante:audit:ouvrir-chaine', ['journal' => 'users', '--motif' => 'x'])
            ->expectsOutputToContain('Journal inconnu')
            ->assertExitCode(1);
    }

    public function test_la_commande_refuse_sans_motif_et_n_ecrit_rien(): void
    {
        $this->journal()->inscrire($this->protocole(), 'brouillon_ouvert', $this->acteur());

        $this->artisan('masante:audit:ouvrir-chaine', ['journal' => 'protocole_journal'])
            ->assertExitCode(1);

        $this->assertSame(1, AuditChaine::query()->where('journal', 'protocole_journal')->count());
    }

    public function test_la_simulation_n_ecrit_rien(): void
    {
        $this->journal()->inscrire($this->protocole(), 'brouillon_ouvert', $this->acteur());

        $this->artisan('masante:audit:ouvrir-chaine', [
            'journal' => 'protocole_journal',
            '--motif' => 'Essai.',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, $this->journal()->verifierChaine()['chaine_courante']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════
    //  LE NOM LISIBLE
    // ═══════════════════════════════════════════════════════════════════════════════════════

    public function test_un_acteur_reel_n_est_jamais_inscrit_comme_systeme(): void
    {
        // Le défaut trouvé en P10b-1 : `$user->name` n'existe pas sur ce modèle, et toute la chaîne
        // de gouvernance des référentiels a enregistré « Système » pour des acteurs humains.
        $referentiel = Referentiel::forceCreate([
            'code' => 'test_nom', 'pays_code' => 'CI', 'libelle' => 'Vecteur',
            'role_responsable' => 'admin_ivoirsante',
        ]);

        app(JournalReferentiel::class)->inscrire($referentiel, 'REFERENTIEL_ENREGISTRE', $this->acteur('Awa'));

        $this->assertSame('Awa Testeuse', ReferentielJournal::query()->orderBy('id')->first()->acteur_nom);
    }

    public function test_une_ecriture_sans_acteur_humain_reste_systeme(): void
    {
        $referentiel = Referentiel::forceCreate([
            'code' => 'test_systeme', 'pays_code' => 'CI', 'libelle' => 'Vecteur',
            'role_responsable' => 'admin_ivoirsante',
        ]);

        app(JournalReferentiel::class)->inscrire($referentiel, 'REFERENTIEL_ENREGISTRE', null);

        $this->assertSame('Système', ReferentielJournal::query()->orderBy('id')->first()->acteur_nom);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════
    //  TOUT JOURNAL DU REGISTRE EST COUVERT — QUEL QUE SOIT LEUR NOMBRE
    // ═══════════════════════════════════════════════════════════════════════════════════════

    /**
     * ═══ RÉÉCRIT EN P10c-3-ii POUR DIRE LA GARANTIE, PAS POUR PASSER ═══
     *
     * La version d'ADR-042 finissait par `assertCount(4, …)`. Deux journaux se sont ajoutés
     * (`predictions_ia`, `retours_cliniques_triage`) et le vecteur est devenu rouge — non parce
     * qu'une garantie avait cédé, mais parce qu'il comptait au lieu de vérifier.
     *
     * Ce que le projet doit protéger n'a jamais été « il y a quatre journaux » : c'est **aucun
     * journal du registre ne vit sans origine déclarée**. Écrit ainsi, le vecteur devient plus fort
     * qu'avant — il tombera le jour où quelqu'un inscrira un journal dans `ChaineAudit::JOURNAUX`
     * en oubliant de déclarer son origine, c'est-à-dire précisément le trou qu'ADR-042 a refermé.
     *
     * Précédent explicite : le vecteur hérité de P6.4d, réécrit pour dire la garantie neuve plutôt
     * que corrigé pour redevenir vert.
     */
    public function test_tout_journal_du_registre_declare_son_origine_a_l_installation(): void
    {
        $this->assertNotEmpty(ChaineAudit::noms(), 'un registre vide ne prouverait rien');

        foreach (ChaineAudit::noms() as $nom) {
            $this->assertTrue(
                ChaineAudit::origineDeclaree($nom, 1),
                "le journal « {$nom} » doit déclarer son origine sur une base neuve"
            );
        }
    }

    /**
     * ═══ AJOUTÉ APRÈS UN ÉCHEC EN BASE RÉELLE, PAS APRÈS UNE RELECTURE ═══
     *
     * `audit_chaines.motif` est un `string(300)`. Une déclaration d'origine plus longue passait
     * ici sans un mot — **SQLite n'impose pas la longueur d'un `VARCHAR`, MySQL si** — et la
     * migration de P10c-3-ii a échoué au premier contact avec la base réelle
     * (`1406 Data too long`), après avoir posé une partie du schéma puisque le DDL MySQL n'est pas
     * transactionnel.
     *
     * Ce vecteur rend la longueur vérifiable des deux côtés. Il vaut pour toute déclaration
     * future, pas seulement pour celles d'aujourd'hui.
     */
    public function test_aucune_declaration_d_origine_ne_depasse_la_capacite_de_sa_colonne(): void
    {
        $declarations = AuditChaine::query()->get(['journal', 'motif', 'acteur_nom']);

        $this->assertNotEmpty($declarations);

        foreach ($declarations as $declaration) {
            $this->assertLessThanOrEqual(
                300, mb_strlen((string) $declaration->motif),
                "le motif du journal « {$declaration->journal} » dépasse la colonne : MySQL le "
                .'refuserait alors que SQLite l\'accepte'
            );
            $this->assertLessThanOrEqual(150, mb_strlen((string) $declaration->acteur_nom));
        }
    }

    /**
     * Le registre et les tables réellement chaînées ne doivent pas diverger.
     *
     * Un journal inscrit au registre sans ses colonnes de chaîne échouerait à la première écriture,
     * en production et pas forcément en test — on le constate ici, avant.
     */
    public function test_chaque_journal_du_registre_porte_ses_colonnes_de_chaine(): void
    {
        foreach (ChaineAudit::noms() as $nom) {
            foreach (['chaine', 'empreinte', 'empreinte_precedente'] as $colonne) {
                $this->assertTrue(
                    Schema::hasColumn($nom, $colonne),
                    "le journal « {$nom} » doit porter la colonne « {$colonne} »"
                );
            }
        }
    }
}
