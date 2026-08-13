<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Jeu de rôles national CDC_10 §3.6 (P1 — Identité). Idempotent. À seeder en premier.
        $this->call(RoleSeeder::class);

        // Utilisateur de test pré-vérifié (connexion : +2250700000000 / password), rôle patient.
        // L'inscription réelle se fait via l'auth téléphone+OTP (Module 2A.1).
        User::factory()->create([
            'nom' => 'Test',
            'prenom' => 'User',
            'telephone' => '+2250700000000',
            'email' => 'test@example.com',
        ])->assignRole('patient');

        // Référentiel des symptômes du triage (Module 1).
        $this->call(SymptomeSeeder::class);

        // Annuaire géolocalisé des structures sanitaires d'Abidjan (Module 3 / 3A.1).
        $this->call(StructureSanitaireSeeder::class);

        // Référentiel des 8 contacts prénatals OMS/PSN-CI (Module 5 / FN4).
        $this->call(EtapePrenataleSeeder::class);

        // Référentiel des seuils de mesure — glycémie, tension, température… (Module 5 / FN5).
        $this->call(ReferentielMesureSeeder::class);

        // Catalogue des médicaments essentiels + prix de référence CENAME (Module 5 / FN7).
        $this->call(MedicamentSeeder::class);

        // P6.3 — Registre des référentiels nationaux gouvernés (CDC_09 §10). APRÈS les seeders de
        // contenu ci-dessus : le registre s'inscrit sur des tables déjà peuplées, faute de quoi la
        // première proposition figerait un instantané vide.
        $this->call(ReferentielRegistreSeeder::class);
    }
}
