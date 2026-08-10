<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * DÉV — Compte « contrôleur plateforme » pour le portail des alertes de fraude IA (CDC_05, ADR-020 §B2).
 *
 * Les alertes de fraude sont réservées à un contrôleur INDÉPENDANT de la structure signalée (ADR-017).
 * Décision propriétaire G1 : accès portail = rôle `super_admin` ou `ministere`. Or aucun compte n'en
 * portait ; ce seeder en crée un pour la démo (G4). Idempotent. Login portail = téléphone + mot de passe.
 *
 * NE PAS exécuter en production : compte de démonstration à identifiants connus.
 */
class ControleurPlateformeSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::firstOrCreate(
            ['telephone' => '+2250709090909'],
            [
                'nom'                 => 'Contrôle',
                'prenom'              => 'Plateforme',
                'password'            => Hash::make('Controle@2026!'),
                'telephone_verified_at' => now(),
            ],
        );

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }

        $this->command?->info('Contrôleur plateforme (dév) : 0709090909 / Controle@2026! (rôle super_admin)');
    }
}
