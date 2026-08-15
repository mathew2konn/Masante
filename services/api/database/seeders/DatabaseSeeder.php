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

        // P6.8a — Vocabulaire des spécialités (CDC_09 §8). AVANT les structures : les services
        // créés juste après y résolvent leur terme, si bien qu'une base fraîchement seedée n'a
        // aucun service orphelin. Le backfill sert alors uniquement les installations existantes.
        $this->call(SpecialiteMedicaleSeeder::class);

        // Annuaire géolocalisé des structures sanitaires d'Abidjan (Module 3 / 3A.1).
        $this->call(StructureSanitaireSeeder::class);

        // Référentiel des 8 contacts prénatals OMS/PSN-CI (Module 5 / FN4).
        $this->call(EtapePrenataleSeeder::class);

        // Référentiel des seuils de mesure — glycémie, tension, température… (Module 5 / FN5).
        $this->call(ReferentielMesureSeeder::class);

        // Catalogue des médicaments essentiels + prix de référence CENAME (Module 5 / FN7).
        $this->call(MedicamentSeeder::class);

        // P6.8b — Vaccins et calendrier vaccinal (CDC_09 §8). JEU DE DÉMONSTRATION : chaque
        // échéance porte `source = 'demonstration'`, le contrôle qualité l'exige et l'écran en
        // affiche le compte. Le calendrier officiel du PEV n'a pas été vu ; le charger sera de la
        // donnée, zéro code. Ce seeder alimente les tables et NE PUBLIE RIEN — la mise en vigueur
        // passe par le quatre-yeux du §10.
        $this->call(VaccinSeeder::class);

        // P6.8c — Maladies (CDC_09 §8). APRÈS `VaccinSeeder` : il relie les vaccins aux maladies
        // dont ils protègent, et cette liaison suppose que les vaccins existent. JEU DE
        // DÉMONSTRATION, AUCUN CODE CIM — CIM-10 et CIM-11 sont des publications de l'OMS ; les
        // charger sera de la donnée, zéro code. Ne publie rien : la mise en vigueur passe par le §10.
        $this->call(MaladieSeeder::class);

        // P6.8d — Organismes d'assurance agréés (CDC_09 §8). JEU DE DÉMONSTRATION : la CNAM, que le
        // CDC_06 §8.1 nomme, et cinq organismes explicitement FICTIFS — un par famille du §8.2.
        // Aucun assureur privé réel n'est nommé : l'écrire dans un registre « d'organismes agréés »
        // affirmerait un agrément que personne n'a vu. Ne publie rien (§10).
        $this->call(OrganismeAssuranceSeeder::class);

        // P6.4b — Villes couvertes (Abidjan, Yamoussoukro, Bouaké) + rattachement des structures.
        $this->call(VilleSeeder::class);

        // P6.4c — Catégories d'images (CDC_11 §3.1). Table de référence, aucune image déposée.
        $this->call(CategoriesImageEtablissementSeeder::class);

        // P6.4 — Découpage sanitaire (régions, districts) + rattachement des structures.
        // APRÈS `StructureSanitaireSeeder` : il rattache des structures qui doivent exister.
        $this->call(DecoupageSanitaireSeeder::class);

        // P6.3 — Registre des référentiels nationaux gouvernés (CDC_09 §10). APRÈS les seeders de
        // contenu ci-dessus : le registre s'inscrit sur des tables déjà peuplées, faute de quoi la
        // première proposition figerait un instantané vide.
        $this->call(ReferentielRegistreSeeder::class);
    }
}
