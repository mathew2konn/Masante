<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * P11.0 — RÉCONCILIATION DES RÔLES : onze noms, aucun doublon, aucun compte orphelin.
 *
 * ── CE QUE LE G0 A TROUVÉ ────────────────────────────────────────────────────────────────────
 *
 * Quatorze rôles vivaient en base. Trois d'entre eux étaient les copies dormantes de trois
 * autres, nées de ce que l'enum `Role` de `@masante/shared` et le seeder Laravel avaient été
 * écrits séparément, à des modules d'écart :
 *
 *   `secretaire`          (0 permission) ≡ `agent_garde`                 (5 permissions)
 *   `admin_etablissement` (0 permission) ≡ `gestionnaire_etablissement`  (8 permissions)
 *   `super_admin`         (0 permission) ≡ `admin_ivoirsante`           (40 permissions)
 *
 * Sept autres rôles portaient zéro permission — ils reçoivent les leurs dans
 * `PortailRolesSeeder`, pas ici : cette migration ne s'occupe que des NOMS.
 *
 * ── LA RÈGLE DE DÉPARTAGE ────────────────────────────────────────────────────────────────────
 *
 * On adopte le nom qui porte déjà quelque chose, on n'en réinvente aucun (précédent P6.8a, où
 * les codes de spécialité ont été adoptés plutôt qu'inventés pour ne pas casser le contrat de
 * P3). Les deux premiers cas sont donc évidents. Le troisième l'est moins et mérite d'être dit :
 * `super_admin` avait un consommateur réel — la garde du module fraude (ADR-020 §B2), qui
 * l'avait choisi **faute de mieux**, son propre ADR notant qu'`admin_finance` était « absent de
 * l'enum `Role` ». Ce n'était pas une décision d'architecture mais un pis-aller ; la garde nomme
 * désormais le rôle survivant. Le contrôleur indépendant qu'exige ADR-017 §7 reste `ministere`.
 *
 * ── DEUX PRÉCAUTIONS ─────────────────────────────────────────────────────────────────────────
 *
 * 1. **On transfère avant de supprimer.** Un compte porteur d'un nom retiré reçoit d'abord le
 *    nom survivant. Supprimer d'abord ferait disparaître l'attribution par cascade et
 *    « nettoierait » silencieusement un utilisateur de son rôle — la panne muette que ce projet
 *    refuse partout ailleurs.
 * 2. **Le renommage échoue bruyamment si le nom d'arrivée est déjà pris.** Si `agent_garde` et
 *    `personnel_accueil` coexistaient, un `UPDATE` violerait l'unicité de `roles.name` ; on
 *    préfère la lever nous-mêmes avec un message qui dit quoi faire, plutôt qu'une erreur 1062
 *    nue au milieu d'un déploiement.
 *
 * Réversible : `down()` rétablit les trois noms d'origine. Il ne rétablit pas QUI portait quoi —
 * l'information est perdue par construction, et c'est dit plutôt que déguisé.
 */
return new class extends Migration
{
    /** Renommages purs : le rôle survivant change simplement d'étiquette. */
    private const RENOMMAGES = [
        'agent_garde' => 'personnel_accueil',
    ];

    /** Absorptions : le rôle de gauche disparaît, ses porteurs reçoivent celui de droite. */
    private const ABSORPTIONS = [
        'secretaire' => 'personnel_accueil',
        'admin_etablissement' => 'gestionnaire_etablissement',
        'super_admin' => 'admin_ivoirsante',
    ];

    public function up(): void
    {
        foreach (self::RENOMMAGES as $avant => $apres) {
            $this->renommer($avant, $apres);
        }

        foreach (self::ABSORPTIONS as $retire => $survivant) {
            $this->absorber($retire, $survivant);
        }

        $this->oublierLeCache();
    }

    public function down(): void
    {
        // On ne recrée que les noms. Les attributions transférées ne reviennent pas : savoir
        // qui portait `super_admin` plutôt qu'`admin_ivoirsante` n'existe plus nulle part.
        foreach (self::ABSORPTIONS as $retire => $survivant) {
            DB::table('roles')->insertOrIgnore([
                'name' => $retire,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::RENOMMAGES as $avant => $apres) {
            $this->renommer($apres, $avant);
        }

        $this->oublierLeCache();
    }

    /** Change l'étiquette d'un rôle, en refusant bruyamment d'écraser un nom déjà pris. */
    private function renommer(string $avant, string $apres): void
    {
        $source = $this->role($avant);

        if ($source === null) {
            return; // base neuve : le seeder posera directement le bon nom.
        }

        if ($this->role($apres) !== null) {
            throw new RuntimeException(
                "Impossible de renommer le rôle « {$avant} » en « {$apres} » : les deux existent "
                ."déjà en base. Fusionnez-les à la main (transférez les comptes de « {$avant} » "
                ."vers « {$apres} », puis supprimez « {$avant} ») avant de rejouer cette migration."
            );
        }

        DB::table('roles')->where('id', $source->id)->update([
            'name' => $apres,
            'updated_at' => now(),
        ]);
    }

    /** Transfère les porteurs d'un rôle retiré vers son survivant, puis supprime le retiré. */
    private function absorber(string $retire, string $survivant): void
    {
        $source = $this->role($retire);

        if ($source === null) {
            return;
        }

        $cible = $this->role($survivant);

        if ($cible === null) {
            throw new RuntimeException(
                "Le rôle « {$retire} » doit être absorbé par « {$survivant} », qui n'existe pas en "
                .'base. Exécutez `db:seed --class=RoleSeeder` avant cette migration.'
            );
        }

        // Les porteurs qui n'ont pas déjà le rôle survivant le reçoivent. `insertOrIgnore` ne
        // suffirait pas seul : la table porte une clé composite, mais un porteur peut déjà
        // cumuler les deux rôles, et on ne veut ni doublon ni erreur.
        $porteurs = DB::table('model_has_roles')
            ->where('role_id', $source->id)
            ->get(['model_type', 'model_id']);

        foreach ($porteurs as $porteur) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $cible->id,
                'model_type' => $porteur->model_type,
                'model_id' => $porteur->model_id,
            ]);
        }

        // Puis on retire l'ancien : d'abord ses attributions et ses permissions, ensuite lui.
        // L'ordre est explicite plutôt que confié aux cascades — une cascade absente sur un
        // moteur donné passerait inaperçue jusqu'au premier déploiement réel.
        DB::table('model_has_roles')->where('role_id', $source->id)->delete();
        DB::table('role_has_permissions')->where('role_id', $source->id)->delete();
        DB::table('roles')->where('id', $source->id)->delete();
    }

    private function role(string $nom): ?object
    {
        return DB::table('roles')
            ->where('name', $nom)
            ->where('guard_name', 'web')
            ->first(['id']);
    }

    /** Sans cela, spatie continuerait de servir l'ancienne liste depuis son cache. */
    private function oublierLeCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
