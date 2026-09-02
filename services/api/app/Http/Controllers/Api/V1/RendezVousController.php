<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\Triage;
use App\Services\RecuRdvService;
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
    public function __construct(private readonly RecuRdvService $recus) {}

    /**
     * Liste des RDV des membres du compte authentifié.
     *
     * B1-b (D7) — chaque RDV porte désormais un APERÇU du tarif (`tarif`/`tarif_source`, calculé
     * par `RecuRdvService::tarifPour()`, jamais persisté ici) et `regle` (un reçu existe-t-il déjà
     * ?) : c'est ce qui permet à la fiche mobile d'afficher le montant SANS naviguer vers l'écran
     * de paiement, et de distinguer « Payer » de « Voir le reçu ».
     *
     * Les colonnes des relations sont volontairement ÉLARGIES par rapport à avant B1-b :
     * `tarifPour()` lit `service.tarif_consultation_cfa`/`medecin.tarif_consultation`/
     * `structure.tarif_min_cfa`, et la fiche affiche désormais `medecin.numero_professionnel` et
     * `medecin.photo_uuid` (D5) — un chargement à colonnes restreintes qui les omettrait rendrait
     * ces champs silencieusement NULL (l'accesseur `photo_url` en particulier, voir `Medecin`).
     */
    public function index(Request $request): JsonResponse
    {
        $rdv = RendezVous::whereHas('membre', fn ($m) => $m->where('user_id', $request->user()->id))
            ->with([
                'membre:id,nom,prenom',
                'structure:id,nom,commune,tarif_min_cfa',
                'service:id,nom_service,specialite,tarif_consultation_cfa',
                'medecin:id,titre,nom,prenom,specialite,numero_professionnel,photo_uuid,tarif_consultation',
                'recu:id,rendez_vous_id',
            ])
            ->latest()
            ->get();

        $rdv->each(function (RendezVous $r) {
            $tarif = $this->recus->tarifPour($r);
            $r->setAttribute('tarif', $tarif[0] ?? null);
            $r->setAttribute('tarif_source', $tarif[1] ?? null);
            $r->setAttribute('regle', $r->recu !== null);
            // Le détail du reçu n'a rien à faire dans la liste (montant/QR/transaction) : seul son
            // EXISTENCE importe ici. Le charger n'était qu'un moyen d'éviter un N+1.
            $r->makeHidden('recu');
        });

        return response()->json(['rendez_vous' => $rdv]);
    }

    /** Crée une demande de RDV (statut en_attente). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membre_id' => ['required', 'integer', 'exists:membres_famille,id'],
            'structure_id' => ['required', 'integer', 'exists:structures_sanitaires,id'],
            'service_id' => ['required', 'integer', 'exists:services_etablissement,id'],
            'medecin_id' => ['nullable', 'integer', 'exists:medecins,id'],
            'triage_id' => ['nullable', 'integer', 'exists:triages,id'],
            'motif' => ['required', 'string', 'max:2000'],
            'date_souhaitee' => ['required', 'date', 'after_or_equal:today'],
            // B1-b (D6) — texte libre et facultatif, DISTINCT du médecin référent (table
            // `referents`, propre à ADR non touchée ici). Ne conditionne aucune règle métier :
            // affichage seul sur la fiche staff.
            'motif_orientation' => ['nullable', 'string', 'max:150'],
            'message_orientation' => ['nullable', 'string', 'max:1000'],
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
            'membre_id' => $data['membre_id'],
            'structure_id' => $data['structure_id'],
            'service_id' => $data['service_id'],
            'medecin_id' => $medecinId,
            'mode_attribution' => $medecinId ? 'patient_choisit' : 'etablissement_attribue',
            'triage_id' => $data['triage_id'] ?? null,
            'motif_orientation' => $data['motif_orientation'] ?? null,
            'message_orientation' => $data['message_orientation'] ?? null,
            'motif' => $data['motif'],
            'date_souhaitee' => $data['date_souhaitee'],
            'statut' => 'en_attente',
        ]);

        return response()->json(['rendez_vous' => $rdv], 201);
    }

    /**
     * B1-b (D6) — Associe un triage APRÈS COUP : le lien existe depuis toujours (`triage_id`,
     * déjà posé par `store()` à la création), mais rien ne permettait de l'ajouter plus tard —
     * un patient qui réalise après coup que son triage éclaire ce RDV devait recommencer une
     * demande entière. Mêmes vérifications anti-IDOR que `store()`, à l'identique.
     */
    public function associerTriage(Request $request, RendezVous $rendezVous): JsonResponse
    {
        if ($rendezVous->membre->user_id !== $request->user()->id) {
            abort(403, 'Accès refusé.');
        }

        if (in_array($rendezVous->statut, ['annule', 'refuse', 'honore'], true)) {
            throw ValidationException::withMessages([
                'statut' => 'Ce rendez-vous ne peut plus être modifié.',
            ]);
        }

        $data = $request->validate([
            'triage_id' => ['required', 'integer', 'exists:triages,id'],
        ]);

        $triage = Triage::findOrFail($data['triage_id']);
        if ($triage->user_id !== $request->user()->id) {
            abort(403, 'Ce triage n\'appartient pas à votre compte.');
        }

        $rendezVous->update(['triage_id' => $triage->id]);

        return response()->json(['rendez_vous' => $rendezVous]);
    }

    /** Annulation par le patient (RDV en attente ou confirmé d'un de ses membres). */
    public function annuler(Request $request, RendezVous $rendezVous): JsonResponse
    {
        // Anti-IDOR : le RDV doit concerner un membre du compte.
        if ($rendezVous->membre->user_id !== $request->user()->id) {
            abort(403, 'Accès refusé.');
        }

        if (! in_array($rendezVous->statut, ['en_attente', 'prevalide', 'confirme'], true)) {
            throw ValidationException::withMessages([
                'statut' => 'Ce rendez-vous ne peut plus être annulé.',
            ]);
        }

        $rendezVous->update(['statut' => 'annule']);

        return response()->json(['rendez_vous' => $rendezVous]);
    }
}
