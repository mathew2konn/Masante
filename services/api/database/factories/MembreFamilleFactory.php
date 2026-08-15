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
            // P6.8d — plus de `cmu_*` par défaut : ces colonnes ne sont plus lues ni écrites, et un
            // membre neuf n'en porte aucune. Sa couverture, s'il en a une, vit dans
            // `couvertures_membre`. Les remplir ici fabriquerait à chaque test une donnée héritée
            // que le code de production ne crée plus.
        ];
    }

    /**
     * Un membre tel qu'il existait AVANT P6.8d : sa couverture dans les trois colonnes héritées.
     *
     * Réservé aux vecteurs de bascule (`masante:couvertures:backfill`). L'état porte le mot
     * « hérité » pour que personne ne l'emploie en croyant déclarer une couverture — il en fabrique
     * au contraire une que plus aucun écran n'écrit.
     */
    public function avecCmuHerite(string $statut = 'actif', ?string $numero = 'CMU12345678', ?string $validite = null): static
    {
        return $this->state(fn (): array => [
            'cmu_numero'   => $numero,
            'cmu_statut'   => $statut,
            'cmu_validite' => $validite,
        ]);
    }
}
