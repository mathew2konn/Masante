<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4 / 4.1 — Rôles et permissions du portail administratif (Sécurité §4.1, CdC §5.4.2).
 *
 * Trois profils RBAC (spatie/laravel-permission), guard `web` (portail à sessions). Idempotent.
 * Crée aussi un compte ADMIN de bootstrap : sans lui, personne ne pourrait se connecter au portail
 * pour créer le premier établissement (le workflow 5.4.1 part de l'admin IVOIRSANTÉ).
 */
class PortailRolesSeeder extends Seeder
{
    /** Toutes les permissions du portail. */
    private const PERMISSIONS = [
        // Admin IVOIRSANTÉ
        'etablissement.manage',   // créer / modifier / supprimer des établissements
        'compte.manage',          // gérer tous les comptes
        'moderation.manage',      // modérer avis + signalements
        'stats.global',           // statistiques globales
        'sante_publique.manage',  // publier / gérer les alertes épidémiques (FN3)
        // Gestionnaire d'établissement
        'service.manage',         // CRUD de SES services
        'agent.manage',           // créer / gérer SES agents
        'medecin.manage',         // annuaire des praticiens de SON établissement (RDV F3.5 + référent 5.6)
        'don_sang.manage',        // publier les besoins en sang de SON établissement (FN6)
        'medicament.manage',      // prix et ruptures de SA pharmacie (FN7/FN8, modèle freemium)
        'stats.etablissement',    // statistiques de SON établissement
        // Agent de garde
        'disponibilite.manage',   // mettre à jour la dispo de SON service
        'rdv.validate',           // valider / refuser les RDV
        'qr.scan',                // scanner le QR patient (carnet / RDV)
        'triage.view',            // consulter la fiche de triage reçue
        'dossier.referent',       // ouvrir sans QR le dossier des patients qui vous ont désigné (5.6)
        // Urgences — VOLONTAIREMENT ATTRIBUÉE À AUCUN RÔLE (Note_Continuite §5.3) : le gestionnaire
        // l'accorde individuellement, et seulement aux agents d'un service d'urgences.
        'urgence.bris_de_glace',  // ouvrir le vital minimal d'un patient hors d'état de consentir
        // Écriture au carnet (D0) — VOLONTAIREMENT ATTRIBUÉE À AUCUN RÔLE, même précédent :
        // `agent_garde` porte `qr.scan` et sert l'accueil ; un agent d'accueil ne rédige pas une
        // ordonnance. Le gestionnaire l'accorde individuellement aux soignants habilités.
        'dossier.ecrire',         // consigner un acte dans le carnet, pendant une session ouverte
    ];

    /** Permissions par rôle (moindre privilège). L'admin reçoit tout. */
    private const ROLES = [
        // `medicament.manage` est donnée à tous les gestionnaires, mais l'écran se referme sur ceux
        // dont l'établissement n'est pas une PHARMACIE (revérifié à chaque accès, comme le bris de
        // glace vérifie le service d'urgences) : la permission dit le rôle, pas la nature du lieu.
        'gestionnaire_etablissement' => [
            'service.manage', 'agent.manage', 'medecin.manage', 'don_sang.manage', 'medicament.manage',
            'stats.etablissement', 'rdv.validate', 'disponibilite.manage',
        ],
        // La permission `dossier.referent` ne donne accès à RIEN à elle seule : encore faut-il que le
        // gestionnaire ait relié le compte à une fiche de l'annuaire, ET qu'un patient ait désigné ce
        // praticien. C'est le patient qui ouvre la porte, pas le rôle (Sécurité §4.4).
        'agent_garde' => [
            'disponibilite.manage', 'rdv.validate', 'qr.scan', 'triage.view', 'dossier.referent',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $nom) {
            Permission::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }

        // Admin = toutes les permissions.
        $admin = Role::firstOrCreate(['name' => 'admin_ivoirsante', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        // Gestionnaire + agent = sous-ensembles.
        foreach (self::ROLES as $role => $perms) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])->syncPermissions($perms);
        }

        $this->creerAdminBootstrap();
    }

    /** Compte admin de démarrage (login portail par email + mot de passe). */
    private function creerAdminBootstrap(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@masante.ci'],
            [
                'nom'                   => 'Admin',
                'prenom'                => 'IVOIRSANTÉ',
                'password'              => Hash::make('Admin@2026!'),
                'email_verified_at'     => now(),
            ],
        );

        if (! $user->hasRole('admin_ivoirsante')) {
            $user->assignRole('admin_ivoirsante');
        }

        $this->command?->info('Admin portail : admin@masante.ci / Admin@2026!');
    }
}
