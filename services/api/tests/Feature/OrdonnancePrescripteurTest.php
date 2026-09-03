<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\EcritureSoignantService;
use App\Services\Pki\AutoriteCertification;
use App\Services\Pki\DocumentOrdonnance;
use App\Services\Pki\ServiceSignature;
use App\Services\ServiceConsultation;
use App\Services\SessionDossierService;
use App\Support\RegistreSectionsCarnet;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B2-c — l'ordonnance désigne enfin son prescripteur (constat Y2 du G0 de B2).
 *
 * CE QUE CETTE SUITE PROTÈGE. `ordonnances.medecin_nom` est une CHAÎNE, fiable depuis P6.5a — le
 * serveur la réécrit — mais **une valeur fiable n'est pas un lien** : « toutes les ordonnances du
 * D<sup>r</sup> X » et « ce prescripteur exerce-t-il encore ? » étaient insolubles.
 *
 * ET LE POINT LE PLUS SENSIBLE N'EST PAS L'AJOUT, C'EST CE QU'IL NE DOIT PAS CASSER. La signature
 * d'une ordonnance (P6.5b) porte sur `medecin_nom`, pas sur un identifiant. Ajouter les liens au
 * contenu canonique ferait passer « altérée » toute ordonnance signée avant ce jour, alors que
 * personne n'y a touché — *une signature qui casse toute seule ne prouve plus rien, et pire, elle
 * accuse*. D'où le vecteur obligatoire de cette suite.
 */
class OrdonnancePrescripteurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    /** @return array{0: User, 1: Medecin, 2: StructureSanitaire} */
    private function soignantAvecFiche(): array
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);

        $user = User::factory()->create(['structure_id' => $structure->id]);
        $user->givePermissionTo('dossier.ecrire');

        $fiche = Medecin::create([
            'user_id' => $user->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'nom' => 'Kablan', 'prenom' => 'Koffi', 'specialite' => 'cardiologie',
        ]);

        // `numero_professionnel` et `pays_code` sont HORS `$fillable` (P6.5a : un client ne choisit
        // pas son numéro national), donc assignation directe — comme le fait
        // `AttributeurNumeroProfessionnel`. Sans eux, la PKI refuse d'émettre : « ce professionnel
        // n'est pas au référentiel », et c'est la garde de P6.5b qui parle.
        $fiche->forceFill([
            'numero_professionnel' => 'PRO000777',
            'pays_code' => 'CI',
            // La profession décide de l'habilitation à prescrire (6e contrôle du §5.4, P6.5b) :
            // un kinésithérapeute n'est pas prescripteur. C'est un fait ADMINISTRATIF, pas une
            // règle médicale.
            'profession' => 'medecin_generaliste',
        ])->save();

        return [$user->fresh(), $fiche->fresh(), $structure];
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    private function ouvrirSession(MembreFamille $membre, User $soignant): AccesDossier
    {
        $acces = AccesDossier::create([
            'membre_id' => $membre->id,
            'agent_id' => $soignant->id,
            'type_acces' => 'qr_scan',
            'etablissement' => 'CHU de Cocody',
        ]);

        app(SessionDossierService::class)->ouvrir($acces);

        return $acces;
    }

    /** @return array<string, mixed> */
    private function ordonnance(array $extra = []): array
    {
        return array_merge([
            'medecin_nom' => 'Saisi par le client',
            'structure_sanitaire' => 'Saisie par le client',
            'date_prescription' => '2026-09-03',
            'medicaments_json' => [['nom' => 'Paracétamol 500 mg', 'posologie' => '1 cp x3/j']],
        ], $extra);
    }

    private function ecriture(): EcritureSoignantService
    {
        return app(EcritureSoignantService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que B2-c referme
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_ordonnance_du_soignant_designe_sa_fiche_et_son_etablissement(): void
    {
        [$soignant, $fiche, $structure] = $this->soignantAvecFiche();
        $patient = $this->patient();

        /** @var Ordonnance $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant, $patient, 'qr_scan', 'ordonnances', $this->ordonnance()
        );

        $this->assertSame($fiche->id, $entree->medecin_id);
        $this->assertSame($structure->id, $entree->structure_id);
        // Le NOM reste celui que le serveur réécrit depuis la fiche — c'est lui qui est signé.
        $this->assertSame($fiche->nom_complet, $entree->medecin_nom);
        // Et la relation répond enfin à la question que la chaîne ne pouvait pas.
        $this->assertSame($fiche->id, $entree->medecin->id);
    }

    /**
     * LE CHEMIN DU PATIENT N'EST PAS TOUCHÉ. Quelqu'un qui recopie une ordonnance papier n'a pas de
     * fiche professionnelle : sa ligne reste sans lien, et c'est correct — inventer un prescripteur
     * serait pire que ne pas en avoir.
     */
    public function test_une_ordonnance_saisie_par_le_patient_ne_designe_aucun_prescripteur(): void
    {
        $patient = $this->patient();

        $entree = $patient->ordonnances()->create($this->ordonnance());

        $this->assertNull($entree->medecin_id);
        $this->assertNull($entree->structure_id);
        $this->assertNull($entree->consultation_id);
        $this->assertSame('Saisi par le client', $entree->medecin_nom);
    }

    public function test_un_soignant_sans_fiche_professionnelle_ecrit_sans_lien(): void
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU sans fiche', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $soignant = User::factory()->create(['structure_id' => $structure->id]);
        $soignant->givePermissionTo('dossier.ecrire');

        /** @var Ordonnance $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant->fresh(), $this->patient(), 'qr_scan', 'ordonnances', $this->ordonnance()
        );

        // L'écriture passe — le trou du G0 de P7-D0 reste refermé — mais rien n'est inventé.
        $this->assertDatabaseCount('ordonnances', 1);
        $this->assertNull($entree->medecin_id);
    }

    /**
     * LA DISTINCTION ÉTABLIE PAR P6.7b, DÉSORMAIS DÉCLARÉE AU REGISTRE. Sur un résultat d'analyse,
     * celui qui consigne est souvent quelqu'un d'autre que le prescripteur : y poser l'identifiant
     * de l'auteur inscrirait le mauvais médecin — c'est précisément la faute que P6.7b a corrigée
     * pour le nom, et qu'on ne réintroduit pas par l'identifiant.
     */
    public function test_un_resultat_d_analyse_ne_recoit_pas_le_prescripteur_de_son_auteur(): void
    {
        [$soignant] = $this->soignantAvecFiche();

        $entree = $this->ecriture()->ecrire(
            $soignant, $this->patient(), 'qr_scan', 'resultats-analyses',
            [
                'type_analyse' => 'biologique',
                'intitule' => 'Hémogramme',
                'date_analyse' => '2026-09-03',
                'medecin_prescripteur' => 'Dr Quelqu\'un d\'Autre',
            ],
        );

        // Le prescripteur DÉCLARÉ est conservé, et aucun identifiant n'est posé à sa place.
        $this->assertSame('Dr Quelqu\'un d\'Autre', $entree->medecin_prescripteur);
        $this->assertNull($entree->medecin_prescripteur_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le rattachement à la consultation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_ordonnance_ecrite_pendant_une_consultation_s_y_rattache(): void
    {
        [$soignant] = $this->soignantAvecFiche();
        $patient = $this->patient();
        $this->ouvrirSession($patient, $soignant);
        $consultation = app(ServiceConsultation::class)->ouvrir($soignant, 'Fièvre');

        /** @var Ordonnance $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant, $patient, 'qr_scan', 'ordonnances', $this->ordonnance()
        );

        $this->assertSame($consultation->id, $entree->consultation_id);
        $this->assertSame($consultation->id, $entree->consultation->id);
    }

    /**
     * Une écriture HORS consultation reste possible et laisse le lien nul : une ordonnance vit dans
     * le carnet du patient, pas dans la consultation. Exiger un acte ouvert refuserait une écriture
     * par ailleurs légitime.
     */
    public function test_une_ordonnance_ecrite_hors_consultation_laisse_le_lien_nul(): void
    {
        [$soignant] = $this->soignantAvecFiche();
        $patient = $this->patient();
        $this->ouvrirSession($patient, $soignant);

        /** @var Ordonnance $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant, $patient, 'qr_scan', 'ordonnances', $this->ordonnance()
        );

        $this->assertDatabaseCount('ordonnances', 1);
        $this->assertNull($entree->consultation_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que le client ne déclare jamais — une couche, un vecteur (P6.6b)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Couche 1, éprouvée par LE CHEMIN RÉEL : l'API du carnet n'accepte pas ces clés — elles ne
     * figurent pas dans ses règles, donc `validate()` les écarte avant même le service.
     */
    public function test_l_api_du_carnet_n_accepte_pas_les_liens_declares_par_le_client(): void
    {
        $compte = User::factory()->create();
        $membre = MembreFamille::factory()->for($compte)->create();

        $this->actingAs($compte, 'sanctum')
            ->postJson("/api/v1/membres/{$membre->id}/ordonnances", $this->ordonnance([
                'medecin_id' => 999_999,
                'structure_id' => 999_999,
                'consultation_id' => 999_999,
            ]))
            ->assertSuccessful();

        $entree = Ordonnance::firstOrFail();

        $this->assertNull($entree->medecin_id);
        $this->assertNull($entree->structure_id);
        $this->assertNull($entree->consultation_id);
    }

    /**
     * CE VECTEUR A ÉTÉ RÉÉCRIT POUR DIRE LA GARANTIE QUI TIENT, PAS CELLE QU'ON CROYAIT.
     *
     * Il affirmait d'abord une « seconde couche » : le service reposerait les liens quoi que
     * l'appelant déclare. Une mutation a survécu et montré que c'était faux —
     * `EcritureSoignantService::ecrire()` VALIDE d'abord avec les règles de la section, et ces clés
     * n'y figurent pas : elles n'atteignent jamais le code qui pose. Il n'y a donc qu'une couche
     * qui mord, et c'est la validation. Le vecteur dit maintenant cela : un appelant qui déclare
     * des liens absurdes obtient quand même les VRAIS, parce que les siens ont été écartés en
     * amont. (Onzième occurrence dans ce projet de « le vecteur prouve autre chose ».)
     */
    public function test_les_liens_declares_par_l_appelant_n_atteignent_jamais_l_ecriture(): void
    {
        [$soignant, $fiche, $structure] = $this->soignantAvecFiche();

        /** @var Ordonnance $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant, $this->patient(), 'qr_scan', 'ordonnances',
            $this->ordonnance(['medecin_id' => 999_999, 'structure_id' => 999_999]),
        );

        $this->assertSame($fiche->id, $entree->medecin_id);
        $this->assertSame($structure->id, $entree->structure_id);
        $this->assertNotSame(999_999, $entree->medecin_id);
    }

    /**
     * LA DÉCLARATION ELLE-MÊME, ET POURQUOI ELLE MÉRITE SON VECTEUR.
     *
     * Une mutation qui rendait `auteurEstPrescripteur()` toujours vrai a SURVÉCU : poser
     * `medecin_id` sur une table qui n'a pas cette colonne est sans effet, Eloquent l'écarte. La
     * garde est donc CONCEPTUELLE aujourd'hui — elle protège le jour où une autre section gagnera
     * une colonne du même nom, ce qui est exactement le cas de `resultats_analyses` et de son
     * `medecin_prescripteur_id`. On l'éprouve donc là où elle est observable : sur ce qu'elle
     * DÉCLARE, plutôt que sur un effet qu'elle n'a pas encore.
     */
    public function test_seules_les_ordonnances_font_de_leur_auteur_le_prescripteur(): void
    {
        $this->assertTrue(RegistreSectionsCarnet::auteurEstPrescripteur('ordonnances'));

        foreach (['resultats-analyses', 'antecedents', 'vaccinations', 'rappels'] as $section) {
            $this->assertFalse(
                RegistreSectionsCarnet::auteurEstPrescripteur($section),
                "La section « {$section} » ne doit pas faire de son auteur le prescripteur.",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LE VECTEUR OBLIGATOIRE : les signatures existantes ne bougent pas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * UNE ORDONNANCE SIGNÉE AVANT B2-c RESTE INTÈGRE.
     *
     * Le contenu canonique porte `medecin_nom`, la date, la structure et les médicaments — « tout
     * ce dont la modification changerait le sens de la prescription ». Les trois colonnes de B2-c
     * sont des RATTACHEMENTS, au même titre que `triage_id` que ce contenu exclut déjà. Les y
     * ajouter aurait fait passer « altérée » chaque ordonnance signée avant ce jour.
     */
    public function test_une_ordonnance_signee_reste_integre_apres_le_rattachement(): void
    {
        [$soignant, $fiche] = $this->soignantAvecFiche();
        $soignant->givePermissionTo('document.signer');
        $fiche->update(['autorisation_statut' => 'valide']);

        // L'autorité doit exister avant tout certificat : elle est créée par une COMMANDE en
        // exploitation (`masante:pki:autorite`), jamais par un seeder — régénérer une autorité
        // invaliderait toutes les signatures déjà posées (P6.5b). Sa phrase de passe n'a AUCUNE
        // valeur par défaut, et c'est délibéré : une phrase dans le dépôt serait un secret dans le
        // dépôt (CDC_10 §5). Le test la fournit donc explicitement, comme le ferait un exploitant.
        config(['pki.ca_passphrase' => 'phrase-de-passe-de-test']);
        app(AutoriteCertification::class)->creerAutorite();
        app(AutoriteCertification::class)->emettre($fiche->fresh(), 'secret-du-praticien');

        // ═══ LE CAS RÉEL, ET LA PREMIÈRE VERSION DE CE VECTEUR NE LE REPRODUISAIT PAS ═══
        //
        // Une ordonnance signée AVANT B2-c n'a AUCUN lien : elle a été écrite quand les colonnes
        // n'existaient pas. Le vecteur partait d'une ordonnance écrite par le chemin soignant, donc
        // portant déjà `medecin_id` — « reposer » la même valeur ne changeait rien, et la mutation
        // qui ajoutait le lien au contenu signé SURVIVAIT. On part donc d'une ordonnance SANS lien,
        // comme le sont toutes celles d'avant ce jour.
        $patient = $this->patient();
        $entree = $patient->ordonnances()->create($this->ordonnance([
            'medecin_nom' => $fiche->fresh()->nom_complet,
        ]));

        $this->assertNull($entree->medecin_id, 'Le vecteur doit partir dune ordonnance SANS lien.');

        app(ServiceSignature::class)->signer(
            $soignant->fresh(), DocumentOrdonnance::CODE, $entree->id, 'secret-du-praticien'
        );

        $avant = app(ServiceSignature::class)->verifier(DocumentOrdonnance::CODE, $entree->id);
        $this->assertTrue($avant['integre'] ?? false, 'La signature devrait être intègre au départ.');

        // Le geste qu'un rattrapage ferait sur les ordonnances anciennes.
        $entree->forceFill([
            'medecin_id' => $fiche->id,
            'structure_id' => $fiche->structure_id,
        ])->save();

        $apres = app(ServiceSignature::class)->verifier(DocumentOrdonnance::CODE, $entree->id);

        $this->assertTrue(
            $apres['integre'] ?? false,
            'Rattacher une ordonnance ne doit PAS casser sa signature : ce sont des liens, pas la prescription.'
        );
    }

    /** Contre-épreuve : ce qui DOIT casser la signature la casse toujours. */
    public function test_modifier_un_dosage_casse_toujours_la_signature(): void
    {
        [$soignant, $fiche] = $this->soignantAvecFiche();
        $soignant->givePermissionTo('document.signer');
        $fiche->update(['autorisation_statut' => 'valide']);
        config(['pki.ca_passphrase' => 'phrase-de-passe-de-test']);
        app(AutoriteCertification::class)->creerAutorite();
        app(AutoriteCertification::class)->emettre($fiche->fresh(), 'secret-du-praticien');

        /** @var Ordonnance $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant->fresh(), $this->patient(), 'qr_scan', 'ordonnances', $this->ordonnance()
        );
        app(ServiceSignature::class)->signer(
            $soignant->fresh(), DocumentOrdonnance::CODE, $entree->id, 'secret-du-praticien'
        );

        $entree->forceFill([
            'medicaments_json' => [['nom' => 'Paracétamol 1000 mg', 'posologie' => '1 cp x3/j']],
        ])->save();

        $verdict = app(ServiceSignature::class)->verifier(DocumentOrdonnance::CODE, $entree->id);

        $this->assertFalse($verdict['integre'] ?? true, 'Changer un dosage DOIT casser la signature.');
    }
}
