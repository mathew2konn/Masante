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
        // Référentiels nationaux (P6.3, CDC_09 §10 « accès en écriture strictement réservé aux
        // rôles habilités ») — VOLONTAIREMENT ATTRIBUÉES À AUCUN RÔLE MÉTIER, troisième occurrence
        // du même précédent. Deux permissions distinctes et non une seule : le quatre-yeux du §10
        // suppose que proposer et décider puissent être portés par des personnes différentes.
        //
        // À DIRE HONNÊTEMENT : `admin_ivoirsante` les reçoit quand même, comme toutes les autres,
        // par le `syncPermissions(Permission::all())` ci-dessous. Cela n'affaiblit pas le
        // quatre-yeux — qui sépare deux UTILISATEURS, pas deux rôles — mais cela veut dire que le
        // filtrage réel se joue à l'attribution nominative, pas dans cette liste.
        'referentiel.proposer',   // soumettre un changement de référentiel national à décision
        'referentiel.publier',    // décider du sort d'une proposition : la publier ou la rejeter
        // Habilitation professionnelle (P6.5a, CDC_09 §5.2) — CINQUIÈME occurrence du précédent :
        // VOLONTAIREMENT ATTRIBUÉE À AUCUN RÔLE MÉTIER, et ici la raison est plus forte qu'ailleurs.
        //
        // `medecin.manage` laisse un gestionnaire décrire les praticiens de SON établissement :
        // nom, spécialité, tarif, service. Mais l'AUTORISATION D'EXERCER n'est pas une donnée
        // d'établissement — elle est délivrée et retirée par un ordre professionnel. Laisser un
        // hôpital déclarer ses propres médecins autorisés reviendrait à lui faire signer le
        // contrôle qui le vise : c'est le §5.4 qui s'appuiera dessus pour laisser signer une
        // ordonnance, et il serait alors adossé à une déclaration de l'intéressé.
        'professionnel.habiliter', // déclarer / modifier l'autorisation d'exercer et le n° d'ordre
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
        // ═══ P6.5a — LE RÔLE `medecin` DEVIENT UTILISABLE (décision propriétaire P5) ═══
        //
        // Il existait depuis P1 et n'était accepté par aucun portail : un praticien qui écrivait
        // au carnet en P7-D0 le faisait sous un compte `agent_garde`, sous l'identité d'un agent
        // d'accueil. Une signature électronique n'aurait alors désigné personne.
        //
        // POURQUOI `dossier.ecrire` LUI EST DONNÉE ALORS QU'ELLE N'EST DONNÉE À AUCUN AUTRE RÔLE.
        // Le commentaire de P7-D0 disait : « `agent_garde` porte `qr.scan` et sert l'accueil ; un
        // agent d'accueil ne rédige pas une ordonnance — le gestionnaire l'accorde individuellement
        // aux soignants habilités. » L'exclusion visait un rôle d'accueil faute de rôle de soin.
        // Ce rôle de soin existe désormais : c'est exactement le destinataire que la phrase
        // annonçait. Les TROIS GARDES CUMULATIVES de D0 restent intégralement en place —
        // permission, voie consentie (`qr_scan` ou `referent`, jamais le bris de glace), liste
        // blanche des sections.
        //
        // CE QU'IL NE REÇOIT PAS, ET POURQUOI. `disponibilite.manage` et `rdv.validate` restent à
        // l'accueil et au secrétariat : CDC_11 §9 prévoit bien une validation finale par le
        // médecin, mais ce circuit est celui de P4, validé G5, et on ne le rouvre pas au détour
        // d'un incrément sur les référentiels. `medecin.manage` non plus — un praticien ne se
        // décrit pas lui-même dans l'annuaire national.
        'medecin' => [
            'qr.scan', 'triage.view', 'dossier.referent', 'dossier.ecrire',
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
