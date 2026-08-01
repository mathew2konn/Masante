<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            // Téléphone CI : +225 suivi de 10 chiffres (identifiant principal).
            'telephone' => '+225'.fake()->unique()->numerify('##########'),
            'telephone_verified_at' => now(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indique un compte dont le téléphone n'est pas encore vérifié (OTP en attente).
     */
    public function nonVerifie(): static
    {
        return $this->state(fn (array $attributes) => [
            'telephone_verified_at' => null,
        ]);
    }
}
