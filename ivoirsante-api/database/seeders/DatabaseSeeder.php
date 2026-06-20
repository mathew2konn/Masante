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
        // Utilisateur de test pré-vérifié (connexion : +2250700000000 / password).
        // L'inscription réelle se fait via l'auth téléphone+OTP (Module 2A.1).
        User::factory()->create([
            'nom' => 'Test',
            'prenom' => 'User',
            'telephone' => '+2250700000000',
            'email' => 'test@example.com',
        ]);

        // Référentiel des symptômes du triage (Module 1).
        $this->call(SymptomeSeeder::class);

        // Annuaire géolocalisé des structures sanitaires d'Abidjan (Module 3 / 3A.1).
        $this->call(StructureSanitaireSeeder::class);
    }
}
