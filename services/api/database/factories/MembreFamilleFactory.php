<?php

namespace Database\Factories;

use App\Models\MembreFamille;
use App\Models\User;
use App\Services\MatriculeService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembreFamille>
 */
class MembreFamilleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'matricule_ivs'  => app(MatriculeService::class)->generer(),
            'nom'            => fake()->lastName(),
            'prenom'         => fake()->firstName(),
            'date_naissance' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'sexe'           => fake()->randomElement(['M', 'F']),
            'groupe_sanguin' => fake()->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
            'cmu_numero'     => fake()->numerify('CMU########'),
            'cmu_statut'     => 'actif',
        ];
    }
}
