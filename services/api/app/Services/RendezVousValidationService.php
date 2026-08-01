<?php

namespace App\Services;

use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * RendezVousValidationService — SOURCE UNIQUE de la validation staff des RDV (Module 4 / 4.4).
 *
 * Les transitions d'état, le périmètre (services gérés) et les règles de saisie vivent ICI, et
 * sont appelés à la fois par le portail Blade existant et par la nouvelle API (portail Next.js) :
 * une seule vérité, aucune divergence. Frontière CDC (transitions = backend uniquement).
 *
 * Flux réel implémenté : `en_attente` → `confirme` (date définitive + médecin optionnel) OU
 * `refuse` (motif obligatoire). Seul un RDV `en_attente` est traitable.
 */
class RendezVousValidationService
{
    /** Statuts filtrables dans la file d'attente. */
    public const STATUTS = ['en_attente', 'confirme', 'refuse', 'annule', 'honore'];

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

    /** Un RDV déjà traité (confirmé/refusé/annulé/honoré) n'est plus modifiable. */
    public function assertTraitable(RendezVous $rdv): void
    {
        abort_if($rdv->statut !== 'en_attente', Response::HTTP_CONFLICT, 'Ce rendez-vous a déjà été traité.');
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
            'medecin_id'     => ['nullable', Rule::exists('medecins', 'id')->where('service_id', $rdv->service_id)],
            'message_agent'  => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function reglesRefuser(): array
    {
        return ['message_agent' => ['required', 'string', 'max:1000']];
    }

    /** @param array<string, mixed> $data */
    public function confirmer(RendezVous $rdv, array $data): RendezVous
    {
        $this->assertTraitable($rdv);

        $rdv->update([
            'statut'         => 'confirme',
            'date_confirmee' => $data['date_confirmee'],
            'medecin_id'     => $data['medecin_id'] ?? $rdv->medecin_id,
            'message_agent'  => $data['message_agent'] ?? null,
        ]);

        return $rdv;
    }

    /** @param array<string, mixed> $data */
    public function refuser(RendezVous $rdv, array $data): RendezVous
    {
        $this->assertTraitable($rdv);

        $rdv->update(['statut' => 'refuse', 'message_agent' => $data['message_agent']]);

        return $rdv;
    }
}
