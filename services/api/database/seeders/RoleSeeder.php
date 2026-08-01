<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * P1 (Identité) — Jeu de rôles national CDC_10 §3.6, guard `web` (aligné sur l'existant).
 *
 * Idempotent (firstOrCreate). N'attribue PAS de permissions ici : le mobile n'utilise que
 * `patient` ; les autres rôles servent aux portails web (ADR-011) et recevront leurs
 * permissions lors de la construction des modules web. Les rôles Portail existants
 * (admin_ivoirsante, gestionnaire_etablissement, agent_garde) sont conservés tels quels.
 */
class RoleSeeder extends Seeder
{
    /** Valeurs = mêmes chaînes que l'enum Role de @masante/shared (contrat front/back). */
    private const ROLES = [
        'patient',
        'medecin',
        'infirmier',
        'secretaire',
        'pharmacien',
        'laborantin',
        'radiologue',
        'admin_etablissement',
        'super_admin',
        'ministere',
        'assurance',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $nom) {
            Role::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }
    }
}
