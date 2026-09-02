<?php

namespace App\Services;

use App\Http\Controllers\Portail\ScanController;
use App\Models\FacturePatient;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * RendezVousValidationService — SOURCE UNIQUE de la validation staff des RDV (Module 4 / 4.4).
 *
 * Les transitions d'état, le périmètre (services gérés), les règles de saisie ET l'autorisation
 * par action vivent ICI, et sont appelés à la fois par le portail Blade existant et par la nouvelle
 * API (portail Next.js) : une seule vérité, aucune divergence. Frontière CDC (transitions =
 * backend uniquement).
 *
 * ═══ B1-a — LE VRAI WORKFLOW À DEUX ÉTAPES (CDC_11 §9.1) ═══
 *
 * Avant B1-a, ce service ne codait qu'une seule transition (`en_attente → confirme|refuse`), et
 * `personnel_accueil`/`medecin` partageaient la même permission `rdv.validate` pour l'appeler :
 * rien ne distinguait leurs rôles, malgré le libellé « workflow deux étapes complet » du P4
 * validé G5. Le §9.1 est littéral : « **Le médecin fait la validation finale.** »
 *
 * Flux réel désormais implémenté :
 *   `en_attente` → `prevalide` (accueil, `previsalider()`, permission `rdv.prevalider`)
 *   `prevalide`  → `confirme`  (médecin, `confirmer()`, permission `rdv.validate`)
 *   `en_attente` OU `prevalide` → `refuse` (l'un ou l'autre, motif obligatoire)
 *
 * L'AUTORISATION EST VÉRIFIÉE ICI, PAS DANS CHAQUE CONTRÔLEUR : les deux contrôleurs (Blade +
 * API Next) délégueraient sinon la même vérification à deux endroits, avec le risque qu'ils
 * divergent un jour. `previsalider()`/`confirmer()`/`refuser()` prennent l'utilisateur en
 * paramètre et abortent eux-mêmes en 403 si la permission manque.
 */
class RendezVousValidationService
{
    /** Statuts filtrables dans la file d'attente. */
    public const STATUTS = ['en_attente', 'prevalide', 'confirme', 'refuse', 'annule', 'honore'];

    public function __construct(
        private readonly RecuRdvService $recus,
        private readonly ServiceNotification $notifications,
    ) {}

    /** Identifiants des services gérés par l'utilisateur, ou 403 si aucun (compte hors périmètre). */
    public function serviceIds(User $user): array
    {
        $ids = $user->servicesGeresIds();
        abort_if($ids === [], Response::HTTP_FORBIDDEN, 'Aucun service à gérer pour ce compte.');

        return $ids;
    }

    /** Le RDV appartient-il au périmètre de l'utilisateur ? 404 sinon (anti-énumération). */
    public function assertPerimetre(User $user, RendezVous $rdv): void
    {
        abort_if(! in_array($rdv->service_id, $this->serviceIds($user), true), Response::HTTP_NOT_FOUND);
    }

    /**
     * Règles de confirmation, partagées Blade + API (le médecin doit relever du service du RDV).
     *
     * @return array<string, mixed>
     */
    public static function reglesConfirmer(RendezVous $rdv): array
    {
        return [
            'date_confirmee' => ['required', 'date', 'after_or_equal:today'],
            'medecin_id' => ['nullable', Rule::exists('medecins', 'id')->where('service_id', $rdv->service_id)],
            'message_agent' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function reglesRefuser(): array
    {
        return ['message_agent' => ['required', 'string', 'max:1000']];
    }

    /**
     * Étape 1 (accueil) — un RDV `en_attente` devient `prevalide`. Ne fixe ni date définitive ni
     * médecin : ce n'est pas une confirmation, c'est un passage de relais au médecin.
     *
     * @param  array<string, mixed>  $data
     */
    public function previsalider(User $user, RendezVous $rdv, array $data = []): RendezVous
    {
        abort_unless($user->can('rdv.prevalider'), Response::HTTP_FORBIDDEN, 'Action réservée à la pré-validation des rendez-vous.');
        $this->assertStatut($rdv, ['en_attente'], 'Ce rendez-vous a déjà été traité.');

        $rdv->update([
            'statut' => 'prevalide',
            'message_agent' => $data['message_agent'] ?? $rdv->message_agent,
            // B1-d (D11) — jusqu'ici, rien ne captait QUI avait pré-validé : seul le check-in
            // (Module 4) était tracé. Distinct à dessein du check-in : le prévalidateur n'est pas
            // forcément l'agent qui enregistre l'arrivée physique du patient.
            'prevalide_par_agent_id' => $user->id,
        ]);

        return $rdv;
    }

    /**
     * Étape 2 (médecin) — validation finale. Exige un RDV déjà `prevalide` : un accueil qui
     * n'aurait plus `rdv.validate` ne peut plus court-circuiter l'étape 1.
     *
     * @param  array<string, mixed>  $data
     */
    public function confirmer(User $user, RendezVous $rdv, array $data): RendezVous
    {
        abort_unless($user->can('rdv.validate'), Response::HTTP_FORBIDDEN, 'Action réservée à la validation finale des rendez-vous.');
        $this->assertStatut($rdv, ['prevalide'], "Ce rendez-vous doit d'abord être pré-validé par l'accueil.");

        $rdv->update([
            'statut' => 'confirme',
            'date_confirmee' => $data['date_confirmee'],
            'medecin_id' => $data['medecin_id'] ?? $rdv->medecin_id,
            'message_agent' => $data['message_agent'] ?? null,
        ]);

        return $rdv;
    }

    /**
     * Refus — accessible à l'accueil (d'emblée, sur `en_attente`) OU au médecin (au dernier
     * moment, sur `prevalide`). Motif toujours obligatoire (communiqué au patient).
     *
     * @param  array<string, mixed>  $data
     */
    public function refuser(User $user, RendezVous $rdv, array $data): RendezVous
    {
        abort_unless(
            $user->can('rdv.prevalider') || $user->can('rdv.validate'),
            Response::HTTP_FORBIDDEN,
            'Action réservée à la validation des rendez-vous.',
        );
        $this->assertStatut($rdv, ['en_attente', 'prevalide'], 'Ce rendez-vous a déjà été traité.');

        $rdv->update(['statut' => 'refuse', 'message_agent' => $data['message_agent']]);

        return $rdv;
    }

    /**
     * B1-d (D10) — clôt le rendez-vous : `confirme → honore`. Permission `rdv.validate` (le
     * médecin de la consultation, ou le gestionnaire en supervision — même répartition que
     * {@see confirmer()}).
     *
     * ═══ CORRECTION DU PLAN G1, ÉCRITE ICI PLUTÔT QUE DÉGUISÉE ═══
     *
     * Le plan (D10) attendait que cette action « complète/génère » la `FacturePatient`. Le G0 de
     * B1-d a établi que ce n'est plus vrai : depuis B1-c, l'enregistrement à l'accueil
     * ({@see ScanController::checkIn()}) exige un reçu valide, qui
     * n'existe QUE si le patient a déjà réglé ({@see RecuRdvService::payer()}) — et c'est lui-même
     * un préalable à toute ouverture d'accès partagé ({@see PartageRdvService}). La
     * facture existe donc TOUJOURS, déjà `PAYEE`, avant qu'on puisse seulement atteindre cette
     * méthode : il n'y a rien à générer.
     *
     * Ce qui restait réellement à faire — et qui n'existait nulle part — c'est la clôture du RDV
     * lui-même : `honore` figure dans {@see STATUTS} depuis B1-a mais AUCUNE transition ne
     * l'atteignait (clé morte, précédent `RendezVousStatut` de B1-a). `terminer()` la referme, et
     * VÉRIFIE — contre nos deux seules sources de vérité aujourd'hui — que la consultation a
     * réellement eu lieu, plutôt que de le supposer : le patient enregistré à l'accueil
     * ({@see RendezVous::estEnregistre()}, comme le préalable de D8) ET le règlement acquis
     * ({@see RecuRdvService::estRegle()}). Le paiement précède TOUJOURS le check-in
     * ({@see RecuRdvService::payer()} n'exige rien d'autre), mais l'inverse n'est pas vrai —
     * chaque garde reste vérifiée séparément, aucune ne rattrape l'autre.
     */
    public function terminer(User $user, RendezVous $rdv): RendezVous
    {
        abort_unless($user->can('rdv.validate'), Response::HTTP_FORBIDDEN, 'Action réservée à la validation finale des rendez-vous.');
        $this->assertStatut($rdv, ['confirme'], 'Ce rendez-vous doit être confirmé avant d\'être clos.');

        abort_unless(
            $rdv->estEnregistre(),
            Response::HTTP_CONFLICT,
            'Le patient doit être enregistré à l\'accueil avant la clôture du rendez-vous.',
        );

        abort_unless(
            $this->recus->estRegle($rdv),
            Response::HTTP_CONFLICT,
            'Le règlement de ce rendez-vous doit être vérifié avant sa clôture.',
        );

        $rdv->update([
            'statut' => 'honore',
            'termine_le' => now(),
            'termine_par_agent_id' => $user->id,
        ]);

        $this->notifications->rendezVousTermine(
            $rdv,
            FacturePatient::where('rendez_vous_id', $rdv->id)->latest('id')->first(),
        );

        return $rdv;
    }

    /** Un RDV hors des statuts attendus pour l'action demandée n'est pas traitable ainsi. */
    private function assertStatut(RendezVous $rdv, array $attendus, string $message): void
    {
        abort_if(! in_array($rdv->statut, $attendus, true), Response::HTTP_CONFLICT, $message);
    }
}
