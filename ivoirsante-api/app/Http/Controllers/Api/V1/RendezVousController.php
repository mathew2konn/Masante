<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\Triage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 3 / étape 3A.2 — Demande de rendez-vous côté patient (F3.6).
 *
 * Toutes les routes sont authentifiées. L'isolation anti-IDOR (§4.3) est assurée à deux niveaux :
 *  - on ne liste/n'agit QUE sur les RDV des membres du compte ;
 *  - à la création, le membre et le triage joints doivent appartenir au compte (sinon 403/422).
 * La validation par l'agent (confirmation, refus, date confirmée) relève du Module 4.
 */
class RendezVousController extends Controller
{
    /** Liste des RDV des membres du compte authentifié. */
    public function index(Request $request): JsonResponse
    {
        $rdv = RendezVous::whereHas('membre', fn ($m) => $m->where('user_id', $request->user()->id))
            ->with([
                'membre:id,nom,prenom',
                'structure:id,nom,commune',
                'service:id,nom_service,specialite',
                'medecin:id,titre,nom,prenom,specialite',
            ])
            ->latest()
            ->get();

        return response()->json(['rendez_vous' => $rdv]);
    }

    /** Crée une demande de RDV (statut en_attente). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membre_id'      => ['required', 'integer', 'exists:membres_famille,id'],
            'structure_id'   => ['required', 'integer', 'exists:structures_sanitaires,id'],
            'service_id'     => ['required', 'integer', 'exists:services_etablissement,id'],
            'medecin_id'     => ['nullable', 'integer', 'exists:medecins,id'],
            'triage_id'      => ['nullable', 'integer', 'exists:triages,id'],
            'motif'          => ['required', 'string', 'max:2000'],
            'date_souhaitee' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $user = $request->user();

        // Anti-IDOR : le membre doit appartenir au compte.
        $membre = MembreFamille::findOrFail($data['membre_id']);
        if ($membre->user_id !== $user->id) {
            abort(403, 'Ce membre n\'appartient pas à votre compte.');
        }

        // Le service doit appartenir à la structure ciblée.
        $service = ServiceEtablissement::findOrFail($data['service_id']);
        if ($service->structure_id !== (int) $data['structure_id']) {
            throw ValidationException::withMessages([
                'service_id' => 'Ce service n\'appartient pas à la structure choisie.',
            ]);
        }

        // Le médecin choisi (F3.5), s'il est fourni, doit appartenir au service ET à la structure ciblés.
        // Absence de médecin = l'établissement attribue (le praticien sera fixé par l'agent au Module 4).
        if (! empty($data['medecin_id'])) {
            $medecin = Medecin::findOrFail($data['medecin_id']);
            if ($medecin->service_id !== (int) $data['service_id']
                || $medecin->structure_id !== (int) $data['structure_id']) {
                throw ValidationException::withMessages([
                    'medecin_id' => 'Ce médecin n\'exerce pas dans le service choisi.',
                ]);
            }
        }

        // Le triage joint (fiche F1.8), s'il est fourni, doit appartenir au compte.
        if (! empty($data['triage_id'])) {
            $triage = Triage::findOrFail($data['triage_id']);
            if ($triage->user_id !== $user->id) {
                abort(403, 'Ce triage n\'appartient pas à votre compte.');
            }
        }

        $medecinId = $data['medecin_id'] ?? null;

        $rdv = RendezVous::create([
            'membre_id'        => $data['membre_id'],
            'structure_id'     => $data['structure_id'],
            'service_id'       => $data['service_id'],
            'medecin_id'       => $medecinId,
            'mode_attribution' => $medecinId ? 'patient_choisit' : 'etablissement_attribue',
            'triage_id'        => $data['triage_id'] ?? null,
            'motif'            => $data['motif'],
            'date_souhaitee'   => $data['date_souhaitee'],
            'statut'           => 'en_attente',
        ]);

        return response()->json(['rendez_vous' => $rdv], 201);
    }

    /** Annulation par le patient (RDV en attente ou confirmé d'un de ses membres). */
    public function annuler(Request $request, RendezVous $rendezVous): JsonResponse
    {
        // Anti-IDOR : le RDV doit concerner un membre du compte.
        if ($rendezVous->membre->user_id !== $request->user()->id) {
            abort(403, 'Accès refusé.');
        }

        if (! in_array($rendezVous->statut, ['en_attente', 'confirme'], true)) {
            throw ValidationException::withMessages([
                'statut' => 'Ce rendez-vous ne peut plus être annulé.',
            ]);
        }

        $rendezVous->update(['statut' => 'annule']);

        return response()->json(['rendez_vous' => $rendezVous]);
    }
}
