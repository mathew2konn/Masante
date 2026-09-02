<?php

namespace App\Services;

use App\Events\PartageRdvFerme;
use App\Events\PartageRdvOuvert;
use App\Models\AccesDossier;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use App\Support\DiffusionPresence;
use App\Support\TypeAccesDossier;
use Symfony\Component\HttpFoundation\Response;

/**
 * B1-c — Partage temporaire d'accès (30 min) vers le médecin d'UN rendez-vous précis (D8, CDC_11 §9).
 *
 * ═══ CE QUI DISTINGUE CETTE VOIE DE LA VOIE « RÉFÉRENT » (P6.5a), ET POURQUOI CE N'EST PAS UN
 * SECOND MÉCANISME ═══
 *
 * {@see ReferentService::ouvrir()} laisse un médecin PERMANENT ouvrir son accès quand il le
 * souhaite. Rejouer ce mécanisme tel quel ici aurait été faux : la désignation n'est pas
 * permanente, elle ne vaut que pour LE rendez-vous qui l'a rendue possible. La différence tient
 * donc entièrement dans les PRÉCONDITIONS (ci-dessous), jamais dans le mécanisme d'ouverture lui-
 * même — {@see SessionDossierService::ouvrir()} est appelée à l'identique, avec la même durée
 * ({@see SessionDossierService::DUREE_MINUTES}, aucune nouvelle constante).
 *
 * ═══ « INITIÉE PAR L'ACCUEIL » — CE QUE ÇA VEUT DIRE EN PRATIQUE ═══
 *
 * Le plan (D8) parle d'un accueil qui « désigne/scanne le médecin ». Ce service EST appelé par le
 * compte du MÉDECIN (c'est SA session PHP qui doit porter la fenêtre de 30 minutes — l'écriture au
 * carnet, {@see EcritureSoignantService::ecrire()}, vérifie l'habilitation du compte CONNECTÉ ;
 * l'ouvrir depuis le compte de l'accueil ouvrirait un accès que l'accueil ne peut pas exercer).
 * Le rôle de l'accueil est réel mais antérieur : c'est lui qui a FAIT le rendez-vous confirmé
 * (workflow B1-a) et qui a ENREGISTRÉ le patient à son arrivée ({@see RendezVous::estEnregistre()},
 * Module 4 / `ScanController::checkIn`) — c'est ce check-in, déjà existant, qui EST la
 * « désignation » : la trace que l'accueil a bien réceptionné CE patient pour CE rendez-vous, avant
 * que son médecin ne puisse ouvrir le dossier. Rien de neuf n'a dû être construit pour ce constat.
 */
class PartageRdvService
{
    public function __construct(private readonly SessionDossierService $session) {}

    /**
     * Ouvre l'accès partagé pour le compte MÉDECIN connecté, sur SON rendez-vous.
     *
     * Quatre refus, chacun pour une raison distincte — aucun ne rattrape les autres :
     *   1. permission (`rdv.validate`, vérifiée ici ET non seulement par la route — précédent
     *      {@see RendezVousValidationService}, "piège P4 sur rdv.validate") ;
     *   2. anti-IDOR — pas LE médecin de ce rendez-vous précis (404, pas 403 : même famille que
     *      {@see RendezVousValidationService::assertPerimetre()}, un compte hors périmètre ne doit
     *      pas apprendre qu'un autre rendez-vous existe) ;
     *   3. état du rendez-vous — pas encore `confirme` (409) ;
     *   4. check-in — le patient n'est pas encore enregistré à l'accueil (409) : c'est la
     *      « désignation » du plan (D8), déjà tracée par le Module 4.
     */
    public function ouvrir(User $medecinUser, RendezVous $rdv, ?string $ip): AccesDossier
    {
        abort_unless(
            $medecinUser->can('rdv.validate'),
            Response::HTTP_FORBIDDEN,
            'Action réservée au médecin du rendez-vous.',
        );

        $fiche = Medecin::where('user_id', $medecinUser->id)->first();
        abort_if(
            $fiche === null || $rdv->medecin_id !== $fiche->id,
            Response::HTTP_NOT_FOUND,
        );

        abort_unless(
            $rdv->statut === 'confirme',
            Response::HTTP_CONFLICT,
            'Ce rendez-vous doit être confirmé avant d\'ouvrir un accès partagé.',
        );

        abort_unless(
            $rdv->estEnregistre(),
            Response::HTTP_CONFLICT,
            'Le patient doit d\'abord être enregistré à l\'accueil (scan du reçu de rendez-vous).',
        );

        // Une session déjà ouverte dans CE navigateur est close proprement (audit) avant d'en
        // ouvrir une autre — précédent exact de `ScanController::scanner()`.
        $this->session->fermer('nouveau_partage');

        $acces = AccesDossier::create([
            'membre_id' => $rdv->membre_id,
            'agent_id' => $medecinUser->id,
            'type_acces' => TypeAccesDossier::RDV_PARTAGE->value,
            // D2 (précédent référent) — établissement copié au moment de l'accès.
            'etablissement' => $rdv->structure?->nom,
            'rendez_vous_id' => $rdv->id,
            'ip_address' => $ip,
        ]);

        $this->session->ouvrir($acces);

        DiffusionPresence::diffuser(new PartageRdvOuvert($rdv->id, $fiche->nom_complet));

        return $acces;
    }

    /**
     * Ferme l'accès partagé actif du compte connecté. Idempotent-défensif : sans session
     * `rdv_partage` active pour CE rendez-vous, ne fait rien (le bouton "Terminer" peut être
     * cliqué deux fois sans effet indésirable).
     */
    public function fermer(RendezVous $rdv, string $motif = 'manuelle'): void
    {
        if (! $this->session->estActive()
            || $this->session->typeAcces() !== TypeAccesDossier::RDV_PARTAGE->value
            || $this->session->rdvDeclare() !== $rdv->id
        ) {
            return;
        }

        $this->session->fermer($motif);

        DiffusionPresence::diffuser(new PartageRdvFerme($rdv->id));
    }
}
