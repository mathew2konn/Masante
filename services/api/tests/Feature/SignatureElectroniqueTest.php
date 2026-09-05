<?php

namespace Tests\Feature;

use App\Models\CertificatNumerique;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\ServiceEtablissement;
use App\Models\SignatureElectronique;
use App\Models\SignatureJournal;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Pki\AutoriteCertification;
use App\Services\Pki\CoffreCleProfessionnel;
use App\Services\Pki\DocumentOrdonnance;
use App\Services\Pki\JournalSignature;
use App\Services\Pki\ReglesVerificationSignature;
use App\Services\Pki\ServiceSignature;
use App\Services\Professionnel\AttributeurNumeroProfessionnel;
use App\Support\RegistreDocumentsSignables;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P6.5b — PKI, certificats numériques et signature électronique (CDC_09 §5.3/§5.4 ; CDC_10 §4).
 *
 * ═══ CE QUE CETTE SUITE PROTÈGE EN PRIORITÉ ═══
 *
 * 1. **LE SERVEUR SEUL NE PEUT PAS SIGNER.** C'est la promesse du plan G1, et elle ne vaut que si
 *    un test la met à l'épreuve : un vecteur ouvre le coffre avec le bon secret, un autre échoue
 *    avec un secret voisin. Sans le second, « le serveur ne peut pas » ne serait qu'une phrase.
 *
 * 2. **UNE ORDONNANCE MODIFIÉE DEVIENT DÉTECTABLE.** La signature n'empêche pas la modification,
 *    elle la révèle — c'est la définition de l'intégrité au §5.3. Deux vecteurs en miroir : le
 *    document intact se vérifie, le document au dosage changé ne se vérifie plus.
 *
 * 3. **LES CINQ CONTRÔLES DU §5.4 MORDENT VRAIMENT.** Un vecteur par contrôle, et le contenu sain
 *    qui n'en déclenche aucun — des contrôles qui refuseraient tout seraient aussi inutilisables
 *    que des contrôles qui n'attrapent rien.
 *
 * 4. **LE REFUS EST JOURNALISÉ.** Le §5.4 l'exige en toutes lettres.
 */
class SignatureElectroniqueTest extends TestCase
{
    use RefreshDatabase;

    private StructureSanitaire $structure;

    private ServiceEtablissement $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);

        // Sans phrase de passe, l'autorité refuse de naître — c'est voulu, et c'est testé plus bas.
        config(['pki.ca_passphrase' => 'phrase-de-test-du-g3']);

        $this->structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Boulevard de France',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $this->service = ServiceEtablissement::create([
            'structure_id' => $this->structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function autorite(): AutoriteCertification
    {
        $service = app(AutoriteCertification::class);

        if (\App\Models\AutoriteCertification::where('actif', true)->doesntExist()) {
            $service->creerAutorite();
        }

        return $service;
    }

    /** Un praticien complet, autorisé à exercer, relié à un compte. */
    private function praticien(array $remplacements = []): Medecin
    {
        $compte = User::factory()->create(['structure_id' => $this->structure->id]);
        $compte->assignRole('medecin');

        $fiche = Medecin::create(array_merge([
            'structure_id'             => $this->structure->id,
            'service_id'               => $this->service->id,
            'user_id'                  => $compte->id,
            'titre'                    => 'Dr',
            'prenom'                   => 'Aya',
            'nom'                      => 'Koffi',
            'specialite'               => 'Cardiologie',
            'profession'               => 'medecin_specialiste',
            'autorisation_numero'      => 'AUT-2024-118',
            'autorisation_statut'      => 'valide',
            'autorisation_delivree_le' => '2024-01-15',
            'autorisation_expire_le'   => '2030-01-15',
            'actif'                    => true,
        ], $remplacements));

        app(AttributeurNumeroProfessionnel::class)->attribuer($fiche);

        return $fiche->fresh();
    }

    private function ordonnance(?MembreFamille $membre = null, array $medicaments = null): Ordonnance
    {
        $membre = $membre ?? MembreFamille::factory()->create();

        // Par la RELATION et non par `Ordonnance::create(['membre_id' => …])` : `membre_id` n'est
        // pas `$fillable`, précisément pour qu'un client ne puisse pas écrire dans le dossier d'un
        // autre. Le contourner dans un test aurait masqué cette garde.
        return $membre->ordonnances()->create([
            'medecin_nom'         => 'Dr Aya Koffi',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription'   => '2026-08-13',
            'medicaments_json'    => $medicaments ?? [['nom' => 'Paracétamol 500 mg', 'posologie' => '3/jour']],
        ]);
    }

    private function signature(): ServiceSignature
    {
        return app(ServiceSignature::class);
    }

    private function certifier(Medecin $praticien, string $secret = 'secret-de-signature'): CertificatNumerique
    {
        return $this->autorite()->emettre($praticien, $secret);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L'autorité de certification
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_autorite_racine_est_auto_signee_et_valide(): void
    {
        $this->autorite();
        $autorite = \App\Models\AutoriteCertification::where('actif', true)->sole();

        $this->assertTrue($autorite->actif);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $autorite->certificat_pem);
        // Auto-signée : son propre certificat se vérifie avec sa propre clé publique.
        $this->assertSame(1, openssl_x509_verify($autorite->certificat_pem, $autorite->certificat_pem));
    }

    public function test_une_seconde_autorite_est_refusee(): void
    {
        $this->autorite();

        // Idempotente PAR REFUS, pas par silence : régénérer la CA invaliderait tous les
        // certificats émis, donc toutes les signatures posées.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/existe déjà/');

        app(AutoriteCertification::class)->creerAutorite();
    }

    public function test_sans_phrase_de_passe_l_autorite_echoue_bruyamment(): void
    {
        config(['pki.ca_passphrase' => null]);

        // Aucune valeur de repli : ce serait un secret dans le dépôt, et pire, un secret que tout
        // le monde croirait avoir remplacé (même principe que la commission sans seed en P5.5a).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PKI_CA_PASSPHRASE/');

        app(AutoriteCertification::class)->creerAutorite();
    }

    public function test_le_certificat_du_praticien_est_emis_par_notre_autorite(): void
    {
        $praticien  = $this->praticien();
        $certificat = $this->certifier($praticien);

        $this->assertSame('actif', $certificat->statut);
        $this->assertStringContainsString($praticien->numero_professionnel, $certificat->sujet);
        $this->assertTrue($this->autorite()->chaineValide($certificat));
    }

    public function test_un_nouveau_certificat_revoque_le_precedent(): void
    {
        $praticien = $this->praticien();
        $ancien    = $this->certifier($praticien);
        $nouveau   = $this->certifier($praticien, 'un-autre-secret');

        // Un praticien n'a qu'un certificat actif : l'unicité étant applicative (MySQL n'a pas
        // d'index unique partiel), c'est le verrou de l'émission qui la porte.
        $this->assertSame('revoque', $ancien->fresh()->statut);
        $this->assertSame('actif', $nouveau->statut);
        $this->assertSame(1, CertificatNumerique::where('medecin_id', $praticien->id)->where('statut', 'actif')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LE COFFRE — la promesse « le serveur seul ne peut pas signer »
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_coffre_s_ouvre_avec_le_bon_secret(): void
    {
        $coffre = app(CoffreCleProfessionnel::class);
        $scelle = $coffre->sceller("-----BEGIN PRIVATE KEY-----\nFAUSSE\n", 'mon-secret', 'SERIE-1', 7);

        $this->assertStringContainsString(
            'BEGIN PRIVATE KEY',
            $coffre->ouvrir($scelle['cle_privee_chiffree'], $scelle['nonce'], $scelle['sel_kdf'], 'mon-secret', 'SERIE-1', 7),
        );
    }

    public function test_le_coffre_reste_ferme_sans_le_secret(): void
    {
        // LE VECTEUR QUI TIENT LA PROMESSE DU PLAN G1. Sans lui, « le serveur seul ne peut pas
        // signer » ne serait qu'une phrase dans un document.
        $coffre = app(CoffreCleProfessionnel::class);
        $scelle = $coffre->sceller('CLE', 'mon-secret', 'SERIE-1', 7);

        $this->expectException(\RuntimeException::class);

        $coffre->ouvrir($scelle['cle_privee_chiffree'], $scelle['nonce'], $scelle['sel_kdf'], 'mon-secreT', 'SERIE-1', 7);
    }

    public function test_une_cle_scellee_ne_s_ouvre_pas_sur_un_autre_certificat(): void
    {
        // L'AAD n'est pas décorative : recopier `cle_privee_chiffree` d'une ligne vers une autre —
        // ce qu'un accès en écriture à la base permettrait — doit ÉCHOUER, au lieu d'attribuer
        // silencieusement la clé d'un médecin à un autre.
        $coffre = app(CoffreCleProfessionnel::class);
        $scelle = $coffre->sceller('CLE', 'secret', 'SERIE-1', 7);

        $this->expectException(\RuntimeException::class);

        $coffre->ouvrir($scelle['cle_privee_chiffree'], $scelle['nonce'], $scelle['sel_kdf'], 'secret', 'SERIE-1', 99);
    }

    public function test_la_cle_privee_et_le_secret_ne_sortent_jamais_du_serveur(): void
    {
        $certificat = $this->certifier($this->praticien());
        $json = $certificat->toArray();

        foreach (['cle_privee_chiffree', 'nonce', 'sel_kdf', 'secret_hash'] as $cache) {
            $this->assertArrayNotHasKey($cache, $json, "« {$cache} » ne doit jamais être sérialisé.");
        }

        // Ce qui sert à VÉRIFIER, en revanche, doit sortir : le certificat porte la clé publique.
        $this->assertArrayHasKey('certificat_pem', $json);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les cinq contrôles du §5.4 — classe pure, un vecteur chacun
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function etatSain(): array
    {
        return [
            'medecin_id'                => 1,
            'numero_professionnel'      => 'PRO000001',
            'profession'                => 'medecin_specialiste',
            'autorisation_statut'       => 'valide',
            'autorisation_expire_le'    => '2030-01-15',
            'certificat_medecin_id'     => 1,
            'certificat_statut'         => 'actif',
            'certificat_valide_du'      => '2026-01-01T00:00:00+00:00',
            'certificat_valide_jusqu_a' => '2030-01-01T00:00:00+00:00',
            'chaine_valide'             => true,
        ];
    }

    public function test_un_etat_sain_autorise_la_signature(): void
    {
        $this->assertTrue(app(ReglesVerificationSignature::class)->verifier($this->etatSain(), 'ordonnance')->autorise);
    }

    public function test_controle_identite_compte_sans_fiche(): void
    {
        $verdict = app(ReglesVerificationSignature::class)
            ->verifier(array_merge($this->etatSain(), ['medecin_id' => null]), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::IDENTITE, $verdict->controle);
    }

    public function test_controle_certificat_absent(): void
    {
        $verdict = app(ReglesVerificationSignature::class)
            ->verifier(array_merge($this->etatSain(), ['certificat_statut' => null]), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::CERTIFICAT, $verdict->controle);
    }

    public function test_controle_certificat_d_un_autre_praticien(): void
    {
        $verdict = app(ReglesVerificationSignature::class)
            ->verifier(array_merge($this->etatSain(), ['certificat_medecin_id' => 42]), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::CERTIFICAT, $verdict->controle);
    }

    public function test_controle_certificat_hors_chaine(): void
    {
        // Un certificat fabriqué ailleurs et inséré en base passerait tous les autres contrôles.
        $verdict = app(ReglesVerificationSignature::class)
            ->verifier(array_merge($this->etatSain(), ['chaine_valide' => false]), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::CERTIFICAT, $verdict->controle);
    }

    public function test_controle_revocation(): void
    {
        $verdict = app(ReglesVerificationSignature::class)
            ->verifier(array_merge($this->etatSain(), ['certificat_statut' => 'revoque']), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::REVOCATION, $verdict->controle);
    }

    public function test_un_certificat_revoque_ET_expire_est_refuse_pour_revocation(): void
    {
        // L'ORDRE DES CONTRÔLES EST DÉLIBÉRÉ : révocation avant expiration. Le motif journalisé
        // serait sinon « expiré » — vrai, mais il masquerait le fait qui compte en litige.
        $verdict = app(ReglesVerificationSignature::class)->verifier(array_merge($this->etatSain(), [
            'certificat_statut'         => 'revoque',
            'certificat_valide_jusqu_a' => '2020-01-01T00:00:00+00:00',
        ]), 'ordonnance');

        $this->assertSame(ReglesVerificationSignature::REVOCATION, $verdict->controle);
    }

    public function test_controle_expiration(): void
    {
        $verdict = app(ReglesVerificationSignature::class)->verifier(array_merge($this->etatSain(), [
            'certificat_valide_jusqu_a' => '2020-01-01T00:00:00+00:00',
        ]), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::EXPIRATION, $verdict->controle);
    }

    public function test_controle_autorisation_retiree(): void
    {
        $verdict = app(ReglesVerificationSignature::class)
            ->verifier(array_merge($this->etatSain(), ['autorisation_statut' => 'retiree']), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::AUTORISATION, $verdict->controle);
        $this->assertStringContainsString('retirée', (string) $verdict->motif);
    }

    public function test_controle_autorisation_valide_mais_echue(): void
    {
        // Les deux colonnes portent deux faits distincts (P6.5a) : les confondre laisserait passer
        // l'un des deux cas.
        $verdict = app(ReglesVerificationSignature::class)->verifier(array_merge($this->etatSain(), [
            'autorisation_statut'    => 'valide',
            'autorisation_expire_le' => '2020-01-15',
        ]), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::AUTORISATION, $verdict->controle);
    }

    public function test_une_profession_non_prescriptrice_ne_signe_pas_d_ordonnance(): void
    {
        $verdict = app(ReglesVerificationSignature::class)
            ->verifier(array_merge($this->etatSain(), ['profession' => 'kinesitherapeute']), 'ordonnance');

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::HABILITATION, $verdict->controle);
    }

    public function test_l_emission_n_exige_pas_de_certificat_prealable(): void
    {
        // Sinon la boucle serait parfaite : « aucun certificat » interdirait d'en créer un.
        $verdict = app(ReglesVerificationSignature::class)->verifierEmission(
            array_merge($this->etatSain(), ['certificat_statut' => null, 'chaine_valide' => false]),
        );

        $this->assertTrue($verdict->autorise);
    }

    public function test_l_emission_est_refusee_sans_autorisation_d_exercer(): void
    {
        // LA GARDE DE P6.5a EST CE QUI REND L'AUTO-DEMANDE ACCEPTABLE : l'autorité ne certifie que
        // ce que le référentiel affirme déjà, et un praticien ne peut pas s'y déclarer autorisé.
        $verdict = app(ReglesVerificationSignature::class)->verifierEmission(
            array_merge($this->etatSain(), ['autorisation_statut' => null]),
        );

        $this->assertFalse($verdict->autorise);
        $this->assertSame(ReglesVerificationSignature::AUTORISATION, $verdict->controle);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La signature de bout en bout
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_ordonnance_signee_se_verifie(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        $resultat = $this->signature()->verifier(DocumentOrdonnance::CODE, $ordonnance->id);

        $this->assertTrue($resultat['signe']);
        $this->assertTrue($resultat['integre']);
    }

    public function test_une_ordonnance_modifiee_apres_signature_devient_detectable(): void
    {
        // LE VECTEUR CENTRAL DE P6.5b. La signature n'empêche pas la modification, elle la RÉVÈLE :
        // c'est la définition de l'intégrité au §5.3.
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        $ordonnance->update(['medicaments_json' => [['nom' => 'Paracétamol 1000 mg', 'posologie' => '6/jour']]]);

        $resultat = $this->signature()->verifier(DocumentOrdonnance::CODE, $ordonnance->id);

        $this->assertTrue($resultat['signe']);
        $this->assertFalse($resultat['integre']);
        $this->assertStringContainsString('modifié', (string) $resultat['motif']);
    }

    public function test_un_champ_hors_signature_ne_casse_pas_la_signature(): void
    {
        // Le miroir du vecteur précédent, et il est indispensable : une signature qui casserait au
        // moindre geste du patient (ajouter la photo du papier) n'apprendrait plus rien à personne.
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        $ordonnance->update(['photo_url' => 'https://exemple.ci/photo.jpg']);

        $this->assertTrue($this->signature()->verifier(DocumentOrdonnance::CODE, $ordonnance->id)['integre']);
    }

    public function test_le_chiffrement_au_repos_ne_casse_pas_la_signature(): void
    {
        // On signe le SENS, jamais les octets stockés. `medicaments_json` est `encrypted:array` :
        // signer le cryptogramme aurait cassé la signature au premier rechiffrement, sans qu'aucune
        // donnée n'ait bougé — le piège évité en P6.4c pour l'empreinte des images.
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        // Rechiffrement du MÊME clair. Passer par `update()` ne suffirait pas : Eloquent ne
        // réécrit pas une valeur qu'il juge inchangée. On force donc l'écriture, comme le ferait
        // une rotation de clé ou une reprise de données.
        $avant = Ordonnance::find($ordonnance->id)->getRawOriginal('medicaments_json');

        \Illuminate\Support\Facades\DB::table('ordonnances')
            ->where('id', $ordonnance->id)
            ->update([
                'medicaments_json' => \Illuminate\Support\Facades\Crypt::encryptString(
                    json_encode($ordonnance->medicaments_json)
                ),
            ]);

        $apres = Ordonnance::find($ordonnance->id)->getRawOriginal('medicaments_json');

        $this->assertNotSame($avant, $apres, 'Le rechiffrement doit bien produire un cryptogramme différent.');
        $this->assertTrue($this->signature()->verifier(DocumentOrdonnance::CODE, $ordonnance->id)['integre']);
    }

    public function test_signer_sans_le_bon_secret_est_refuse_et_journalise(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        try {
            $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'mauvais');
            $this->fail('La signature aurait dû être refusée.');
        } catch (\RuntimeException $e) {
            // Message générique : ne pas dire combien d'essais restent.
            $this->assertSame('Secret de signature incorrect.', $e->getMessage());
        }

        $this->assertSame(0, SignatureElectronique::count());
        $this->assertSame(1, SignatureJournal::where('action', JournalSignature::SECRET_INVALIDE)->count());
    }

    public function test_le_verrou_temporaire_se_declenche_apres_le_seuil(): void
    {
        config(['pki.secret.echecs_avant_verrou' => 3]);

        $praticien  = $this->praticien();
        $certificat = $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'faux');
            } catch (\RuntimeException) {
                // attendu
            }
        }

        $this->assertNotNull($certificat->fresh()->verrouille_jusqu_a);

        // Même le BON secret est refusé pendant le verrou.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/temporairement bloquée/');

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');
    }

    public function test_le_compteur_d_echecs_est_remis_a_zero_au_succes(): void
    {
        // Sinon cinq erreurs étalées sur six mois finiraient par verrouiller quelqu'un qui n'a
        // jamais rien fait de suspect.
        $praticien  = $this->praticien();
        $certificat = $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        try {
            $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'faux');
        } catch (\RuntimeException) {
            // attendu
        }

        $this->assertSame(1, $certificat->fresh()->echecs_secret);

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        $this->assertSame(0, $certificat->fresh()->echecs_secret);
    }

    public function test_signer_avec_une_autorisation_retiree_est_refuse_et_journalise(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $praticien->update(['autorisation_statut' => 'retiree']);

        try {
            $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');
            $this->fail('La signature aurait dû être refusée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('retirée', $e->getMessage());
        }

        // §5.4 : « l'échec est journalisé ». Le contrôle en cause y figure.
        $entree = SignatureJournal::where('action', JournalSignature::SIGNATURE_REFUSEE)->sole();
        $this->assertSame(ReglesVerificationSignature::AUTORISATION, $entree->details['controle']);
        $this->assertSame(0, SignatureElectronique::count());
    }

    public function test_signer_avec_un_certificat_revoque_est_refuse_POUR_REVOCATION(): void
    {
        // DÉFAUT TROUVÉ AU G2 LIVE, PAS ICI — et c'est la raison d'être de ce vecteur.
        //
        // Le service interrogeait `certificatActif()`. Après révocation, celle-ci ne renvoyait plus
        // rien : les règles concluaient « **aucun certificat n'a été émis** » — ce qui est faux —
        // et le contrôle REVOCATION, que le §5.4 exige nommément, était **inatteignable**.
        //
        // Le premier `expectException` que j'avais écrit passait quand même : il ne vérifiait que
        // « ça refuse », pas « ça refuse POUR LA BONNE RAISON ». En litige, le journal aurait dit
        // autre chose que le fait.
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->autorite()->revoquerLesActifs($praticien, 'Secret compromis.');

        try {
            $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');
            $this->fail('La signature aurait dû être refusée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('révoqué', $e->getMessage());
        }

        $entree = SignatureJournal::where('action', JournalSignature::SIGNATURE_REFUSEE)->sole();
        $this->assertSame(ReglesVerificationSignature::REVOCATION, $entree->details['controle']);
    }

    public function test_une_signature_posee_reste_valide_apres_revocation(): void
    {
        // Une révocation ne réécrit pas le passé : la prescription a été signée quand le
        // certificat était valide. L'invalider rétroactivement effacerait un acte réel.
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');
        $this->autorite()->revoquerLesActifs($praticien, 'Changement de poste.');

        $this->assertTrue($this->signature()->verifier(DocumentOrdonnance::CODE, $ordonnance->id)['integre']);
    }

    public function test_un_document_ne_se_signe_qu_une_fois(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        // Une seconde signature ne dirait rien de plus et rendrait insoluble « laquelle fait foi ? ».
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');
    }

    public function test_le_contexte_du_signataire_est_fige_au_moment_de_signer(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance();

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        // Le praticien change de nom (mariage, correction d'état civil) : la signature garde ce
        // qu'elle affirmait. Motif de l'établissement copié sur `acces_dossier` en P7-D2.
        $praticien->update(['nom' => 'Koffi-Yao']);

        $this->assertSame('Dr Aya Koffi', SignatureElectronique::sole()->signataire_nom);
    }

    public function test_le_journal_ne_contient_aucun_contenu_clinique(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $ordonnance = $this->ordonnance(medicaments: [['nom' => 'Artemether-Lumefantrine', 'posologie' => '2/jour']]);

        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $ordonnance->id, 'secret-de-signature');

        // Le journal PROUVE, il ne recopie pas — et il n'est pas chiffré, lui.
        $tout = SignatureJournal::all()->toJson();

        $this->assertStringNotContainsString('Artemether', $tout);
        $this->assertStringNotContainsString('posologie', $tout);
    }

    public function test_la_chaine_du_journal_detecte_une_alteration(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);
        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $this->ordonnance()->id, 'secret-de-signature');
        $this->signature()->signer($praticien->user, DocumentOrdonnance::CODE, $this->ordonnance()->id, 'secret-de-signature');

        $journal = app(JournalSignature::class);
        $this->assertNull($journal->premierMaillonRompu());

        // Réécrire le NOM de l'acteur suffit à rompre la chaîne : `acteur_nom` entre dans
        // l'empreinte, parce que c'est ce nom-là qu'un humain lit dans un audit (leçon P6.3).
        $premier = SignatureJournal::orderBy('id')->first();
        SignatureJournal::whereKey($premier->id)->update(['acteur_nom' => 'Système']);

        $this->assertSame($premier->id, $journal->premierMaillonRompu());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le registre des sept documents du §4.5
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_sept_types_du_corpus_sont_tous_nommes(): void
    {
        // Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas — et l'on ne
        // prétend nulle part que « la signature couvre les documents médicaux ».
        //
        // B5-a a branché `prescription_biologique`, la garantie neuve tient donc à DEUX branchés
        // sur sept, pas un seul — la garde qui compte reste que les cinq encore manquants disent
        // tous pourquoi.
        $etat = RegistreDocumentsSignables::etatDuCorpus();

        $this->assertCount(7, $etat);
        $this->assertCount(2, array_filter($etat, fn (array $d): bool => $d['branche']));

        foreach ($etat as $document) {
            if (! $document['branche']) {
                $this->assertNotEmpty($document['raison'], "« {$document['code']} » doit dire pourquoi il n'est pas branché.");
            }
        }
    }

    public function test_un_type_hors_registre_est_refuse(): void
    {
        $praticien = $this->praticien();
        $this->certifier($praticien);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pas signable/');

        $this->signature()->signer($praticien->user, 'dossier_secret', 1, 'secret-de-signature');
    }

    public function test_un_document_non_signe_est_dit_non_signe_et_non_invalide(): void
    {
        // « Non signé » n'est pas « invalide » : une ordonnance non signée reste une ordonnance.
        $resultat = $this->signature()->verifier(DocumentOrdonnance::CODE, $this->ordonnance()->id);

        $this->assertFalse($resultat['signe']);
        $this->assertNull($resultat['integre']);
    }
}
