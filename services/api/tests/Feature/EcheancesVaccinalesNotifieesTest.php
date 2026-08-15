<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\User;
use App\Models\Vaccin;
use App\Services\Referentiel\SourceVaccins;
use App\Services\Vaccin\AttributeurCodeVaccin;
use App\Support\TypeNotification;
use Database\Seeders\VaccinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\TestCase;

/**
 * P6.8b — La notification d'échéance vaccinale (décision propriétaire W3).
 *
 * ═══ CE QUE CES VECTEURS DOIVENT TENIR ═══
 *
 *  1. **Rien n'est écrit dans le carnet** : ni `vaccinations`, ni `rappels`. C'est la décision W3 —
 *     la notification obtient le résultat sans ouvrir un quatrième chemin d'écriture.
 *  2. **Une notification, pas une par dose.** À six semaines, le calendrier prévoit quatre
 *     injections le même jour ; quatre notifications identiques feraient cesser de les lire.
 *  3. **Aucun nom de vaccin ne sort.** La règle inviolable de D1 mord ici : un nom de vaccin désigne
 *     une pathologie visée, et cette phrase s'affiche sur un écran verrouillé.
 *  4. **Idempotente par construction** : le déclenchement au jour exact, plus une garde contre le
 *     rejeu manuel du même jour.
 */
class EcheancesVaccinalesNotifieesTest extends TestCase
{
    use GouverneUnReferentiel;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->seed(VaccinSeeder::class);

        foreach (Vaccin::orderBy('id')->get() as $vaccin) {
            app(AttributeurCodeVaccin::class)->attribuer($vaccin);
        }

        $this->publierReferentiel(SourceVaccins::CODE);
    }

    /** Un membre dont l'âge tombe EXACTEMENT sur l'échéance des six semaines. */
    private function nourrisson(int $ageJours = 42): MembreFamille
    {
        return MembreFamille::factory()->for($this->user)->create([
            'date_naissance' => now()->subDays($ageJours)->toDateString(),
        ]);
    }

    private function notifications(): int
    {
        return DatabaseNotification::where('type', TypeNotification::ECHEANCE_VACCINALE->value)->count();
    }

    public function test_le_jour_exact_de_l_echeance_le_responsable_est_prevenu_une_seule_fois(): void
    {
        $this->nourrisson(42);

        $this->artisan('masante:vaccins:echeances')->assertSuccessful();

        // UNE notification, alors que quatre vaccins sont dus à six semaines au jeu de démonstration.
        $this->assertSame(1, $this->notifications());
    }

    public function test_la_notification_ne_nomme_aucun_vaccin(): void
    {
        $this->nourrisson(42);

        $this->artisan('masante:vaccins:echeances')->assertSuccessful();

        $charge = json_encode(DatabaseNotification::first()->data, JSON_UNESCAPED_UNICODE);

        foreach (Vaccin::pluck('libelle')->merge(Vaccin::pluck('abreviation'))->filter() as $nom) {
            $this->assertStringNotContainsString(
                (string) $nom,
                (string) $charge,
                "Le nom « {$nom} » a fui dans la notification : un nom de vaccin est une information de santé.",
            );
        }

        // Elle dit COMBIEN, et renvoie au carnet pour le détail.
        $this->assertStringContainsString('vaccination', (string) $charge);
        $this->assertArrayHasKey('nombre', DatabaseNotification::first()->data);
    }

    public function test_un_rejeu_le_meme_jour_ne_renotifie_pas(): void
    {
        $this->nourrisson(42);

        $this->artisan('masante:vaccins:echeances')->assertSuccessful();
        $this->artisan('masante:vaccins:echeances')->assertSuccessful();

        $this->assertSame(1, $this->notifications());
    }

    public function test_un_jour_sans_echeance_ne_notifie_personne(): void
    {
        // 43 jours : l'échéance des 42 jours est passée d'un jour, le délai de grâce court encore.
        $this->nourrisson(43);

        $this->artisan('masante:vaccins:echeances')->assertSuccessful();

        $this->assertSame(0, $this->notifications());
    }

    public function test_la_fin_du_delai_de_grace_declenche_une_seconde_annonce_distincte(): void
    {
        // 42 (dû) + 14 (grâce publiée) + 1 = 57 jours.
        $this->nourrisson(57);

        $this->artisan('masante:vaccins:echeances')->assertSuccessful();

        $this->assertSame(1, $this->notifications());
        $this->assertTrue(DatabaseNotification::first()->data['en_retard']);
    }

    public function test_les_delegues_en_lecture_sont_prevenus_eux_aussi(): void
    {
        $membre  = $this->nourrisson(42);
        $delegue = User::factory()->create();

        Delegation::create([
            'membre_id'         => $membre->id,
            'titulaire_user_id' => $this->user->id,
            'delegue_user_id'   => $delegue->id,
            'droits'            => Delegation::DROIT_LECTURE,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        $this->artisan('masante:vaccins:echeances')->assertSuccessful();

        // Celui qui emmène l'enfant au centre n'est pas toujours celui qui détient le carnet :
        // c'est le scénario fondateur de P7.
        $this->assertSame(2, $this->notifications());
    }

    public function test_la_commande_n_ecrit_rien_dans_le_carnet(): void
    {
        $this->nourrisson(42);

        $this->artisan('masante:vaccins:echeances')->assertSuccessful();

        // Décision W3 : le calendrier prévient, il n'ouvre pas un quatrième chemin d'écriture.
        $this->assertDatabaseCount('vaccinations', 0);
        $this->assertDatabaseCount('rappels', 0);
    }

    public function test_le_mode_simulation_n_emet_aucune_notification(): void
    {
        $this->nourrisson(42);

        $this->artisan('masante:vaccins:echeances', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $this->notifications());
    }

    public function test_sans_calendrier_en_vigueur_la_commande_echoue_bruyamment(): void
    {
        // On repart d'une base sans publication : un silence laisserait croire qu'aucune échéance
        // n'est due, alors que le calendrier n'est simplement pas en vigueur.
        \App\Models\Referentiel::where('code', SourceVaccins::CODE)->delete();

        $this->nourrisson(42);

        $this->artisan('masante:vaccins:echeances')->assertFailed();

        $this->assertSame(0, $this->notifications());
    }
}
