<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * P1 (Identité) — Jeu de rôles national CDC_10 §3.6, guard `web` (aligné sur l'existant).
 *
 * Idempotent (firstOrCreate). N'attribue AUCUNE permission : c'est le rôle de
 * `PortailRolesSeeder`, qui est la source unique du « qui peut quoi ». Ce seeder-ci n'est que
 * la liste des métiers reconnus par la plateforme.
 *
 * ═══ P11.0 — LA PROMESSE DE CE FICHIER EST TENUE ═══
 *
 * Son commentaire d'origine annonçait que les rôles « recevront leurs permissions lors de la
 * construction des modules web ». C'est cet incrément. Sept d'entre eux étaient restés à zéro
 * permission pendant huit modules, et aucun ne pouvait franchir la porte du portail.
 *
 * La liste passe de onze à onze noms, mais ce ne sont plus les mêmes : trois doublons ont été
 * retirés au profit du nom qui portait déjà quelque chose, et les trois rôles qui font
 * réellement tourner le portail y entrent enfin (ils n'y avaient jamais figuré). Le détail des
 * trois réconciliations et de leurs raisons vit dans l'enum `Role` de `@masante/shared`, source
 * unique dont cette liste est le miroir exact — et la migration
 * `2026_08_30_000002_p11_reconciliation_roles` transfère les comptes concernés.
 */
class RoleSeeder extends Seeder
{
    /** Valeurs = mêmes chaînes que l'enum Role de @masante/shared (contrat front/back). */
    private const ROLES = [
        'patient',
        'medecin',
        'infirmier',
        'personnel_accueil',
        'pharmacien',
        'laborantin',
        'radiologue',
        'gestionnaire_etablissement',
        'admin_ivoirsante',
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
